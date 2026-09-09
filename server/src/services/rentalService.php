<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../utils/scoreApi.php';
require_once __DIR__ . '/../utils/LocalFileHelper.php';
require_once __DIR__ . '/unitService.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class RentalService
{
    private $rentalCollection;
    private $unitCollection;
    private $userCollection;
    private $applicationDraftCollection;
    private $ScoreApi;
    private $localFileHelper;
    private $unitService;

    public function __construct()
    {
        $db = Database::getDb();
        $this->rentalCollection = $db->Rental;
        $this->unitCollection = $db->Unit;
        $this->userCollection = $db->User;
        $this->applicationDraftCollection = $db->ApplicationDraft;
        $this->ScoreApi = new ScoreApi();
        $this->localFileHelper = new LocalFileHelper();
        // $this->unitService = new UnitService();
    }

    private function getUnitService()
    {
        if ($this->unitService === null) {
            $this->unitService = new UnitService();
        }
        return $this->unitService;
    }

    protected function safeDateFormat($dateValue)
    {
        if ($dateValue instanceof UTCDateTime) {
            return $dateValue->toDateTime()->format('Y-m-d\TH:i:s.vP');
        }
        if (is_string($dateValue)) {
            return $dateValue;
        }
        return null;
    }

    // ─── CORRECTING HANDLERS ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Returns every unit ID in this rental's renewal chain, excluding the
     * current unit itself — i.e. every prior-year unit that represents the
     * same physical room and needs to stay locked/freed in sync.
     */
    private function getChainUnitIds($rental, $currentUnitId): array
    {
        $ids = [];

        if (!empty($rental['renewalHistory'])) {
            foreach ($rental['renewalHistory'] as $entry) {
                if (isset($entry['fromUnit']) && (string) $entry['fromUnit'] !== (string) $currentUnitId) {
                    $ids[(string) $entry['fromUnit']] = $entry['fromUnit']; // dedupe by string key
                }
            }
        }

        // fallback for rentals extended before renewalHistory existed
        if (empty($ids) && !empty($rental['renewedFromUnit']) && (string) $rental['renewedFromUnit'] !== (string) $currentUnitId) {
            $ids[(string) $rental['renewedFromUnit']] = $rental['renewedFromUnit'];
        }

        return array_values($ids);
    }

    public function syncRentalService(): bool
    {
        try {
            $units = $this->unitCollection->find();
            foreach ($units as $unit) {
                $unitId = (string) $unit['_id'];
                $unitNumber = $unit['unitNumber'] ?? null;
                error_log("---- Syncing unit {$unitNumber} ({$unitId}) ----");
    
                $rentals = $this->rentalCollection->find(['unit' => $unitId]);
                $normalizedRentals = [];

                foreach ($rentals as $doc) {
                    $selected = $doc['selectedSubUnits'] ?? null;

                    $unitTypeForRental = $selected['roomType'] 
                        ?? $selected['bedType'] 
                        ?? ($unit['unitType'] ?? null);

                    $normalizedRentals[] = [
                        '_id' => (string) $doc['_id'],
                        'status' => $doc['status'] ?? null,
                        'user' => (string) ($doc['user'] ?? ''), 
                        'unitType' => $unitTypeForRental,
                        'selectedSubUnits' => [
                            'type' => $selected['type'] ?? null,
                            'roomType' => $selected['roomType'] ?? null,
                            'bedType' => $selected['bedType'] ?? null,
                            'isAvailable' => $selected['isAvailable'] ?? true,
                            'price' => $selected['price'] ?? null,
                        ],
                    ];
                }
                error_log("Found " . count($normalizedRentals) . " rentals for unit.");
    
                // Filter active rentals
                $activeRentals = array_filter($normalizedRentals, fn($r) => $r['status'] === 'Active');
                error_log("Active rentals count: " . count($activeRentals));
    
                // 1. currentOccupants
                $currentOccupants = count($activeRentals);
    
                // 2. rentedHistory
                $rentedHistory = array_map(fn($r) => $r['_id'], $activeRentals);
    
                // 3. subUnits availability
                $subUnits = $unit['subUnits'] ?? [];
                foreach ($subUnits as &$subUnit) {
                    $isRented = false;
                    foreach ($activeRentals as $r) {
                        $selected = $r['selectedSubUnits'] ?? null;
                        if ($selected) {
                            if (
                                (isset($selected['roomType']) && $selected['roomType'] === ($subUnit['roomType'] ?? null)) ||
                                (isset($selected['bedType']) && $selected['bedType'] === ($subUnit['bedType'] ?? null))
                            ) {
                                $isRented = true;
                                break;
                            }
                        }
                    }
                    $subUnit['isAvailable'] = !$isRented;
                }
    
                // 4. unitStatus
                $capacity = $unit['unitOccupants'] ?? 0;
                $unitStatus = ($currentOccupants >= $capacity) ? 'Occupied' : 'Available';
    
                // Apply update
                $updateData = [
                    'currentOccupants' => $currentOccupants,
                    'rentedHistory' => $rentedHistory,
                    'subUnits' => $subUnits,
                    'unitStatus' => $unitStatus
                ];
                error_log("Updating unit {$unitNumber} with: " . json_encode($updateData));
    
                $this->unitCollection->updateOne(
                    ['_id' => $unit['_id']],
                    ['$set' => $updateData]
                );
            }
            return true;
        } catch (Exception $e) {
            error_log('SyncRentalService error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    private function calculateAddonFees($planName, $parkingData = null, $shuttleData = null)
    {
        $parkingFee = 0.0;
        $shuttleFee = 0.0;

        // Parking fees based on plan
        if (!empty($parkingData) && !empty($parkingData['hasParking'])) {
            if ($planName === '10-month') {
                $parkingFee = 500.0;
            } elseif ($planName === '11-month') {
                $parkingFee = 455.0;
            } elseif ($planName === 'annual') {
                $parkingFee = 5000.0;
            } else {
                $parkingFee = (float) ($parkingData['fee'] ?? 0.0);
            }
        }

        // Shuttle fees based on plan
        if (!empty($shuttleData) && !empty($shuttleData['hasShuttle'])) {
            if ($planName === '10-month') {
                $shuttleFee = 800.0;
            } elseif ($planName === '11-month') {
                $shuttleFee = 800.0;
            } elseif ($planName === 'annual') {
                $shuttleFee = 8000.0;
            } else {
                $shuttleFee = (float) ($shuttleData['fee'] ?? 0.0);
            }
        }

        return [
            'parkingFee' => $parkingFee,
            'shuttleFee' => $shuttleFee
        ];
    }

    private function formatRenewalHistory($history)
    {
        if (empty($history)) {
            return [];
        }
        
        $formatted = [];
        foreach ($history as $entry) {
            $formatted[] = [
                'fromUnit' => isset($entry['fromUnit']) ? (string) $entry['fromUnit'] : null,
                'fromUnitNumber' => $entry['fromUnitNumber'] ?? null,
                'fromSubUnit' => [
                    'roomType' => $entry['fromSubUnit']['roomType'] ?? null,
                    'bedType' => $entry['fromSubUnit']['bedType'] ?? null,
                ],
                'fromYear' => $entry['fromYear'] ?? null,
                'toUnit' => isset($entry['toUnit']) ? (string) $entry['toUnit'] : null,
                'toUnitNumber' => $entry['toUnitNumber'] ?? null,
                'toSubUnit' => [
                    'roomType' => $entry['toSubUnit']['roomType'] ?? null,
                    'bedType' => $entry['toSubUnit']['bedType'] ?? null,
                ],
                'toYear' => $entry['toYear'] ?? null,
                'sameRoom' => $entry['sameRoom'] ?? null,
                'changedAt' => $this->safeDateFormat($entry['changedAt'] ?? null),
            ];
        }
        return $formatted;
    }

    private function extractMatchedPrice($newSubUnit, $oldPriceName, $unitDoc)
    {
        $priceOptions = $newSubUnit['price'] ?? [];
        
        // If price is directly a number or a simple array
        if (is_numeric($priceOptions)) {
            return [
                'name' => $oldPriceName ?? 'default',
                'price' => (float) $priceOptions
            ];
        }
        
        // If price is an array with a single price value
        if (isset($priceOptions['price']) && is_numeric($priceOptions['price'])) {
            return [
                'name' => $oldPriceName ?? $priceOptions['name'] ?? 'default',
                'price' => (float) $priceOptions['price']
            ];
        }
        
        // If price is an array of options (the normal case)
        if (is_array($priceOptions)) {
            // Try to find matching by name
            foreach ($priceOptions as $option) {
                $option = $option instanceof \MongoDB\Model\BSONDocument ? $option->getArrayCopy() : $option;
                if ($oldPriceName && ($option['name'] ?? null) === $oldPriceName) {
                    return $option;
                }
            }
            
            // No match, use first option
            $firstOption = reset($priceOptions);
            if ($firstOption) {
                $firstOption = $firstOption instanceof \MongoDB\Model\BSONDocument 
                    ? $firstOption->getArrayCopy() 
                    : $firstOption;
                return $firstOption;
            }
        }
        
        // Ultimate fallback
        return [
            'name' => $oldPriceName ?? 'default',
            'price' => (float) ($unitDoc['unitPrice'] ?? 0.0)
        ];
    }

    public function reassignUnitService($reassignDetails) {
        try {
            $rentalToUpdate = $this->rentalCollection->findOne([
                '_id' => new ObjectId($reassignDetails['rentalId'])
            ]);
            if (!$rentalToUpdate) {
                throw new Exception('Rental not found');
            }

            if (!empty($rentalToUpdate['renewed'])) {
                throw new Exception('This rental has already been renewed into a new year and cannot be reassigned. Please contact support if this tenant needs to change units.');
            }

            $unitDoc = $this->unitCollection->findOne([
                '_id' => new ObjectId($reassignDetails['newUnitId'])
            ]);
            if (!$unitDoc) {
                throw new Exception('Target unit not found');
            }

            if (!($unitDoc['accessKey']['isShared'] ?? false)) {
                $userDoc = $this->userCollection->findOne(['_id' => $rentalToUpdate['user']]);
                $userGender = $userDoc['gender'] ?? null;
                if (!empty($unitDoc['genderAssignment']) && $unitDoc['genderAssignment'] !== $userGender) {
                    throw new Exception("This unit is only available for {$unitDoc['genderAssignment']}s.");
                }
            }

            $newSubUnit = $reassignDetails['newSubUnit'] ?? null;
            if (!$newSubUnit) {
                throw new Exception('New subunit details missing');
            }

            $unitTypeForRental = $newSubUnit['roomType'] 
                ?? $newSubUnit['bedType'] 
                ?? $unitDoc['unitType'];

            // ============================================================
            // RECALCULATE TOTAL PRICE
            // ============================================================
            // 1. Get base price from the new sub-unit
            $oldPriceName = $rentalToUpdate['selectedSubUnits']['price']['name'] ?? null;
            $matchedPrice = $this->extractMatchedPrice($newSubUnit, $oldPriceName, $unitDoc);

            $basePrice = (float) ($matchedPrice['price'] ?? 0.0);
            $planName = $matchedPrice['name'] ?? null;

            // 2. Use the helper to calculate addon fees
            $parkingData = $rentalToUpdate['parking'] ?? [];
            $shuttleData = $rentalToUpdate['shuttle'] ?? [];
            $fees = $this->calculateAddonFees($planName, $parkingData, $shuttleData);

            $parkingFee = $fees['parkingFee'];
            $shuttleFee = $fees['shuttleFee'];

            // 3. Calculate total rental price
            $totalRentalPrice = (float) $basePrice + (float) $parkingFee + (float) $shuttleFee;

            // ============================================================
            // BUILD UPDATE DATA
            // ============================================================
            $updateData = [
                'unit' => $unitDoc['_id'],
                'unitType' => $unitTypeForRental,
                'selectedSubUnits' => [
                    'type' => $newSubUnit['type'] ?? null,
                    'roomType' => $newSubUnit['roomType'] ?? null,
                    'bedType' => $newSubUnit['bedType'] ?? null,
                    'price' => $matchedPrice ?: $newSubUnit['price'],
                    'isAvailable' => true
                ],
                'rentalPrice' => (float) $totalRentalPrice,
                'unitYear' => (int) ($unitDoc['unitYear'] ?? date('Y')) 
            ];

            // Update parking with correct fee
            if (!empty($parkingData)) {
                $updateData['parking'] = [
                    'hasParking' => (bool) ($parkingData['hasParking'] ?? false),
                    'fee' => (float) $parkingFee
                ];
            }

            // Update shuttle with correct fee
            if (!empty($shuttleData)) {
                $updateData['shuttle'] = [
                    'hasShuttle' => (bool) ($shuttleData['hasShuttle'] ?? false),
                    'fee' => (float) $shuttleFee
                ];
            }

            $this->rentalCollection->updateOne(
                ['_id' => $rentalToUpdate['_id']],
                ['$set' => $updateData]
            );

            // Only update unit availability if rental is Active
            if ($rentalToUpdate['status'] === 'Active') {
                $oldUnitId = $rentalToUpdate['unit'];
                $oldSubUnit = $rentalToUpdate['selectedSubUnits'] ?? null;

                if ($oldSubUnit) {
                    $oldUnit = $this->unitCollection->findOne(['_id' => new ObjectId($oldUnitId)]);
                    if ($oldUnit) {
                        $this->getUnitService()->runUnitChecks(
                            $this->unitCollection->findOne(['_id' => new ObjectId($oldUnitId)]),
                            $oldSubUnit,
                            $rentalToUpdate['_id'],
                            'free'
                        );
                    }
                }

                if ($newSubUnit) {
                    $this->getUnitService()->runUnitChecks(
                        $this->unitCollection->findOne(['_id' => $unitDoc['_id']]),
                        $newSubUnit,
                        $rentalToUpdate['_id'],
                        'lock'
                    );
                }
            }

            error_log("Rental {$rentalToUpdate['_id']} reassigned to unit {$unitDoc['unitNumber']} (status: {$rentalToUpdate['status']})");
            return $this->rentalCollection->findOne(['_id' => new ObjectId($rentalToUpdate['_id'])]);

        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function extendRentalToNewYear($rentalId, $newEndDate, $overrideUnitId = null, $overrideSubUnitFilter = null)
    {
        try {
            $rental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rental) {
                throw new Exception('Rental not found');
            }

            $oldUnit = $this->unitCollection->findOne(['_id' => $rental['unit']]);
            if (!$oldUnit) {
                throw new Exception('Current unit not found');
            }

            $newEndYear = (int) (new DateTime($newEndDate))->format('Y');

            if ($overrideUnitId) {
                $newUnit = $this->unitCollection->findOne([
                    '_id' => new ObjectId($overrideUnitId),
                    'unitYear' => $newEndYear
                ]);
                if (!$newUnit) {
                    throw new Exception("Target unit not found for year {$newEndYear}");
                }
            } else {
                $newUnit = $this->unitCollection->findOne([
                    'unitNumber' => $oldUnit['unitNumber'],
                    'unitYear' => $newEndYear
                ]);
                if (!$newUnit) {
                    throw new Exception("No {$newEndYear} unit found matching unitNumber {$oldUnit['unitNumber']}");
                }
            }

            $subUnitFilter = $rental['selectedSubUnits'] ?? null;
            if (!$subUnitFilter) {
                throw new Exception('Rental has no selected sub-unit to match');
            }

            $targetSubUnitFilter = $overrideSubUnitFilter ?? $subUnitFilter;

            $newSubUnits = $this->getUnitService()->getSubUnitsArray($newUnit);
            $roomIndex = $this->getUnitService()->findSubUnitIndex($newSubUnits, $targetSubUnitFilter);

            if ($roomIndex === null) {
                throw new Exception("No matching sub-unit found in {$newEndYear} unit");
            }

            $targetSubUnit = $newSubUnits[$roomIndex] instanceof \MongoDB\Model\BSONDocument
                ? $newSubUnits[$roomIndex]->getArrayCopy()
                : $newSubUnits[$roomIndex];

            $isAvailable = filter_var($targetSubUnit['isAvailable'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isAvailable === false) {
                throw new Exception("The {$newEndYear} version of this room is already occupied");
            }

            if (($newUnit['currentOccupants'] ?? 0) >= ($newUnit['unitOccupants'] ?? 0)) {
                throw new Exception("The {$newEndYear} unit is already at full capacity");
            }

            if (!($newUnit['accessKey']['isShared'] ?? false)) {
                $userDoc = $this->userCollection->findOne(['_id' => $rental['user']]);
                $userGender = $userDoc['gender'] ?? null;
                if (!empty($newUnit['genderAssignment']) && $newUnit['genderAssignment'] !== $userGender) {
                    throw new Exception("This unit is only available for {$newUnit['genderAssignment']}s.");
                }
            }

            $oldPriceName = $rental['selectedSubUnits']['price']['name'] ?? null;
            $matchedPrice = $this->extractMatchedPrice($targetSubUnit, $oldPriceName, $newUnit);

            if (!$matchedPrice) {
                throw new Exception("No valid price plan found on the {$newEndYear} unit's matching sub-unit");
            }

            $basePrice = (float) ($matchedPrice['price'] ?? 0.0);
            $planName = $matchedPrice['name'] ?? null;

            $parkingData = $rental['parking'] ?? [];
            $shuttleData = $rental['shuttle'] ?? [];
            $fees = $this->calculateAddonFees($planName, $parkingData, $shuttleData);

            $parkingFee = $fees['parkingFee'];
            $shuttleFee = $fees['shuttleFee'];
            $totalRentalPrice = (float) $basePrice + (float) $parkingFee + (float) $shuttleFee;

            // Lock the new unit's sub-unit
            $this->getUnitService()->runUnitChecks(
                $this->unitCollection->findOne(['_id' => $newUnit['_id']]),
                $targetSubUnitFilter,
                $rental['_id'],
                'lock'
            );

            // ============================================================
            // Determine whether this is the same physical room as before
            // ============================================================
            $isSameRoom = (
                ($subUnitFilter['bedType'] ?? null) === ($targetSubUnit['bedType'] ?? null) &&
                ($subUnitFilter['roomType'] ?? null) === ($targetSubUnit['roomType'] ?? null)
            );

            if ($isSameRoom) {
                // Same room across years — keep the chain root locked, just reconcile counts.
                // Preserve renewedFromUnit if this rental was already chained from an earlier
                // year; only set it fresh if this is the first extension.
                $newRenewedFromUnit = $rental['renewedFromUnit'] ?? $oldUnit['_id'];

                $this->getUnitService()->runUnitChecks(
                    $this->unitCollection->findOne(['_id' => $oldUnit['_id']])
                );
            } else {
                // Different room — free the immediate old room, it's no longer occupied
                $this->getUnitService()->runUnitChecks(
                    $this->unitCollection->findOne(['_id' => $oldUnit['_id']]),
                    $subUnitFilter,
                    $rental['_id'],
                    'free'
                );

                // If a chain root exists further back and differs from the immediate old
                // unit, free that too — the whole chain is broken by the room change.
                if (!empty($rental['renewedFromUnit']) && (string) $rental['renewedFromUnit'] !== (string) $oldUnit['_id']) {
                    $priorUnit = $this->unitCollection->findOne(['_id' => $rental['renewedFromUnit']]);
                    if ($priorUnit) {
                        $this->getUnitService()->runUnitChecks($priorUnit, $subUnitFilter, $rental['_id'], 'free');
                    }
                }

                $newRenewedFromUnit = null; // chain broken, nothing left to keep in sync
            }

            $newSelectedSubUnit = [
                'type' => $targetSubUnit['type'] ?? null,
                'roomType' => $targetSubUnit['roomType'] ?? null,
                'bedType' => $targetSubUnit['bedType'] ?? null,
                'price' => [
                    'name' => $matchedPrice['name'] ?? 'default',
                    'price' => (float) ($matchedPrice['price'] ?? 0)
                ],
                'isAvailable' => true
            ];

            // Log this transition — pure audit trail, always appended regardless of same/different room
            $historyEntry = [
                'fromUnit' => $oldUnit['_id'],
                'fromUnitNumber' => $oldUnit['unitNumber'],
                'fromSubUnit' => [
                    'roomType' => $subUnitFilter['roomType'] ?? null,
                    'bedType' => $subUnitFilter['bedType'] ?? null
                ],
                'fromYear' => (int) ($oldUnit['unitYear'] ?? $rental['unitYear'] ?? $newEndYear - 1),
                'toUnit' => $newUnit['_id'],
                'toUnitNumber' => $newUnit['unitNumber'],
                'toSubUnit' => [
                    'roomType' => $targetSubUnit['roomType'] ?? null,
                    'bedType' => $targetSubUnit['bedType'] ?? null
                ],
                'toYear' => $newEndYear,
                'sameRoom' => $isSameRoom,
                'changedAt' => new UTCDateTime()
            ];

            $update = [
                'unit' => $newUnit['_id'],
                'unitType' => $targetSubUnitFilter['bedType'] ?? $targetSubUnitFilter['roomType'] ?? $rental['unitType'],
                'selectedSubUnits' => $newSelectedSubUnit,
                'rentalPrice' => (float) $totalRentalPrice,
                'rentalEndDate' => new UTCDateTime(strtotime($newEndDate) * 1000),
                'unitYear' => $newEndYear,
                'renewed' => true,
                'renewedFromUnit' => $newRenewedFromUnit,
                'renewedToUnit' => $newUnit['_id']
            ];

            if (!empty($parkingData)) {
                $update['parking'] = [
                    'hasParking' => (bool) ($parkingData['hasParking'] ?? false),
                    'fee' => (float) $parkingFee
                ];
            }

            if (!empty($shuttleData)) {
                $update['shuttle'] = [
                    'hasShuttle' => (bool) ($shuttleData['hasShuttle'] ?? false),
                    'fee' => (float) $shuttleFee
                ];
            }

            $this->rentalCollection->updateOne(
                ['_id' => new ObjectId($rentalId)],
                [
                    '$set' => $update,
                    '$push' => ['renewalHistory' => $historyEntry]
                ]
            );

            return $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);

        } catch (Exception $e) {
            error_log('Extend rental to new year error: ' . $e->getMessage());
            throw $e;
        }
    }

    // ─── HANDLERS ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function deleteRentalService(string $rentalId): bool
    {
        try {
            $rental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rental) {
                throw new Exception('Rental not found');
            }

            if (isset($rental['signature']['fileId'])) {
                try {
                    $this->localFileHelper->deleteImage($rental['signature']['fileId']);
                } catch (Exception $e) {
                    error_log("Failed to delete signature image {$rental['signature']['fileId']}: " . $e->getMessage());
                }
            }

            $subUnit = $rental['selectedSubUnits'] ?? null;
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])]);

            if ($unit && $subUnit) {
                $this->getUnitService()->runUnitChecks(
                    $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])]),
                    $subUnit,
                    $rentalId
                );
            }

            $this->userCollection->updateOne(
                ['_id' => new ObjectId($rental['user'])],
                ['$pull' => ['rentals' => new ObjectId($rentalId)]]
            );

            if (($rental['status'] === 'Active' || $rental['status'] === 'Ended') && $unit) {
                $this->unitCollection->updateOne(
                    ['_id' => new ObjectId($rental['unit'])],
                    ['$pull' => ['rentedHistory' => new ObjectId($rentalId)]]
                );
            }

            // 🆕 free EVERY unit in the renewal chain, not just the single renewedFromUnit field
            $chainUnitIds = $this->getChainUnitIds($rental, $rental['unit']);
            foreach ($chainUnitIds as $chainUnitId) {
                $chainUnit = $this->unitCollection->findOne(['_id' => $chainUnitId]);
                if (!$chainUnit || !$subUnit) continue;

                $this->getUnitService()->runUnitChecks(
                    $this->unitCollection->findOne(['_id' => $chainUnit['_id']]),
                    $subUnit,
                    $rentalId
                );
            }

            $this->clearAllRentalDocsService($rentalId);
            $this->rentalCollection->deleteOne(['_id' => new ObjectId($rentalId)]);

            return true;

        } catch (Exception $e) {
            error_log('DeleteRentalService error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateRentalService($rentalId, $rentalDetails)
    {
        try {
            $rentalToUpdate = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rentalToUpdate) {
                throw new Exception('Rental not found');
            }

            if (!empty($rentalDetails['unit']) && is_string($rentalDetails['unit'])) {
                $rentalDetails['unit'] = new ObjectId($rentalDetails['unit']);
            }
            if (!empty($rentalDetails['user']) && is_string($rentalDetails['user'])) {
                $rentalDetails['user'] = new ObjectId($rentalDetails['user']);
            }

            $dateFields = ['applicationDate', 'rentalStartDate', 'rentalEndDate', 'earlyEndDate'];
            foreach ($dateFields as $field) {
                if (!empty($rentalDetails[$field]) && is_string($rentalDetails[$field])) {
                    $rentalDetails[$field] = new MongoDB\BSON\UTCDateTime(
                        (new DateTime($rentalDetails[$field]))->getTimestamp() * 1000
                    );
                }
            }

            $unitToUpdate = $this->unitCollection->findOne(['_id' => $rentalToUpdate['unit']]);
            if (!$unitToUpdate) {
                throw new Exception('Unit not found');
            }

            // ✅ Check if status is set before using it
            $newStatus = $rentalDetails['status'] ?? null;
            $currentStatus = $rentalToUpdate['status'] ?? null;

            if ($newStatus === 'Active' && $unitToUpdate['currentOccupants'] >= $unitToUpdate['unitOccupants']) {
                throw new Exception('Unit is already at full capacity');
            }

            $subUnit = $rentalToUpdate['selectedSubUnits'] ?? null;
            $userDoc = $this->userCollection->findOne(['_id' => $rentalToUpdate['user']]);
            $userGender = $userDoc['gender'] ?? null;

            // ============================================================
            // Approval logic (Pending → Active)
            // ============================================================
            if ($newStatus === 'Active') {
                if ($unitToUpdate['currentOccupants'] >= $unitToUpdate['unitOccupants']) {
                    throw new Exception('Unit is already at full capacity');
                }

                if ($subUnit) {
                    $this->getUnitService()->runUnitChecks(
                        $this->unitCollection->findOne(['_id' => $unitToUpdate['_id']]),
                        $subUnit,
                        $rentalId,
                        'lock'
                    );
                }

                if (!($unitToUpdate['accessKey']['isShared'] ?? false)) {
                    if (empty($unitToUpdate['genderAssignment']) && $userGender) {
                        $this->unitCollection->updateOne(
                            ['_id' => $unitToUpdate['_id']],
                            ['$set' => ['genderAssignment' => $userGender]]
                        );
                    } elseif (!empty($unitToUpdate['genderAssignment']) && $unitToUpdate['genderAssignment'] !== $userGender) {
                        throw new Exception("This unit is only available for {$unitToUpdate['genderAssignment']}s.");
                    }
                }

                // 🆕 walk EVERY unit in the renewal chain, not just the single renewedFromUnit field
                $chainUnitIds = $this->getChainUnitIds($rentalToUpdate, $unitToUpdate['_id']);
                foreach ($chainUnitIds as $chainUnitId) {
                    $chainUnit = $this->unitCollection->findOne(['_id' => $chainUnitId]);
                    if (!$chainUnit || !$subUnit) continue;

                    $this->getUnitService()->runUnitChecks(
                        $this->unitCollection->findOne(['_id' => $chainUnit['_id']]),
                        $subUnit,
                        $rentalId,
                        'lock'
                    );

                    if (!($chainUnit['accessKey']['isShared'] ?? false)) {
                        if (empty($chainUnit['genderAssignment']) && $userGender) {
                            $this->unitCollection->updateOne(
                                ['_id' => $chainUnit['_id']],
                                ['$set' => ['genderAssignment' => $userGender]]
                            );
                        } elseif (!empty($chainUnit['genderAssignment']) && $chainUnit['genderAssignment'] !== $userGender) {
                            throw new Exception("This unit is only available for {$chainUnit['genderAssignment']}s.");
                        }
                    }
                }
            }

            // ============================================================
            // Rejection logic (Pending → Rejected)
            // ============================================================
            if ($newStatus === 'Rejected') {
                if ($subUnit) {
                    $this->getUnitService()->runUnitChecks(
                        $this->unitCollection->findOne(['_id' => $unitToUpdate['_id']]),
                        $subUnit,
                        $rentalId,
                        'free'
                    );
                }
            }

            // ============================================================
            // Revert logic (Active → Pending)
            // ============================================================
            if ($newStatus === 'Pending' && $currentStatus === 'Active') {
                $this->unitCollection->updateOne(
                    ['_id' => $unitToUpdate['_id']],
                    ['$pull' => ['rentedHistory' => new ObjectId($rentalId)]]
                );

                if ($subUnit) {
                    $this->getUnitService()->runUnitChecks(
                        $this->unitCollection->findOne(['_id' => $unitToUpdate['_id']]),
                        $subUnit,
                        $rentalId,
                        'free'
                    );
                }

                // 🆕 free EVERY unit in the renewal chain, not just the single renewedFromUnit field
                $chainUnitIds = $this->getChainUnitIds($rentalToUpdate, $unitToUpdate['_id']);
                foreach ($chainUnitIds as $chainUnitId) {
                    $chainUnit = $this->unitCollection->findOne(['_id' => $chainUnitId]);
                    if (!$chainUnit || !$subUnit) continue;

                    $this->getUnitService()->runUnitChecks(
                        $this->unitCollection->findOne(['_id' => $chainUnit['_id']]),
                        $subUnit,
                        $rentalId,
                        'free'
                    );
                }
            }

            // ============================================================
            // Apply rental updates
            // ============================================================
            $this->rentalCollection->updateOne(
                ['_id' => new ObjectId($rentalId)],
                ['$set' => $rentalDetails]
            );

            return $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);

        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function endRentalService($rentalId)
    {
        try {
            $rental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rental) {
                throw new Exception('Rental not found');
            }

            $this->rentalCollection->updateOne(
                ['_id' => new ObjectId($rentalId)],
                ['$set' => ['status' => 'Ended']]
            );

            $subUnit = $rental['selectedSubUnits'] ?? null;

            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }

            if ($subUnit) {
                $this->getUnitService()->runUnitChecks(
                    $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])]),
                    $subUnit,
                    $rentalId,
                    'free'
                );
            } else {
                $this->getUnitService()->runUnitChecks(
                    $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])])
                );
            }

            // 🆕 free EVERY unit in the renewal chain, not just the single renewedFromUnit field
            $chainUnitIds = $this->getChainUnitIds($rental, $rental['unit']);
            foreach ($chainUnitIds as $chainUnitId) {
                $chainUnit = $this->unitCollection->findOne(['_id' => $chainUnitId]);
                if (!$chainUnit || !$subUnit) continue;

                $this->getUnitService()->runUnitChecks(
                    $this->unitCollection->findOne(['_id' => $chainUnit['_id']]),
                    $subUnit,
                    $rentalId,
                    'free'
                );
            }

            return $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);

        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function earlyEndRentalService($rentalId)
    {
        try {
            $rental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rental) {
                throw new Exception('Rental not found');
            }

            if ($rental['status'] !== 'Active') {
                throw new Exception('Cannot end a rental if it was not approved');
            }

            $this->rentalCollection->updateOne(
                ['_id' => new ObjectId($rentalId)],
                [
                    '$set' => [
                        'status' => 'Ended',
                        'earlyEndDate' => new UTCDateTime()
                    ]
                ]
            );

            $subUnit = $rental['selectedSubUnits'] ?? null;

            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }

            if ($subUnit) {
                $this->getUnitService()->runUnitChecks(
                    $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])]),
                    $subUnit,
                    $rentalId,
                    'free'
                );
            } else {
                $this->getUnitService()->runUnitChecks(
                    $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])])
                );
            }

            // 🆕 same chain fix as endRentalService
            $chainUnitIds = $this->getChainUnitIds($rental, $rental['unit']);
            foreach ($chainUnitIds as $chainUnitId) {
                $chainUnit = $this->unitCollection->findOne(['_id' => $chainUnitId]);
                if (!$chainUnit || !$subUnit) continue;

                $this->getUnitService()->runUnitChecks(
                    $this->unitCollection->findOne(['_id' => $chainUnit['_id']]),
                    $subUnit,
                    $rentalId,
                    'free'
                );
            }

            return $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);

        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function createRentalService(array $rentalDetails, array $signatureImage, ?array $guardianSignatureImage = null)
    {
        try {
            error_log('unitYear debug — unit: ' . var_export($unit['unitYear'] ?? 'MISSING', true) . ', rentalDetails: ' . var_export($rentalDetails['unitYear'] ?? 'MISSING', true));

            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($rentalDetails['unit'])]);
            $user = $this->userCollection->findOne(['_id' => new ObjectId($rentalDetails['user'])]);
            if (!$unit)
                throw new Exception('Unit not found');
            if (!$user)
                throw new Exception('User not found');

            // Reservation and occupancy check
            if (isset($unit['reservedBy']) && (string) $unit['reservedBy'] !== $rentalDetails['user']) {
                throw new Exception('Unit is reserved by another user');
            }
            if ($unit['currentOccupants'] >= $unit['unitOccupants']) {
                throw new Exception('Unit is already at full capacity');
            }

            // Check existing rentals
            $existingRentals = $this->rentalCollection->find([
                'user' => new ObjectId($rentalDetails['user']),
                'status' => ['$in' => ['Pending', 'Active']]
            ]);
            if (iterator_count($existingRentals) > 0) {
                throw new Exception('User already has an active or pending rental');
            }

            // Access key / Gender Assignments
            $accessKey = null;
            $accessKeyIsTrue = isset($rentalDetails['accessKeyIsTrue'])
                ? filter_var($rentalDetails['accessKeyIsTrue'], FILTER_VALIDATE_BOOLEAN)
                : false;
            if ($accessKeyIsTrue) {
                if ($unit['accessKey']['isShared'] ?? false) {
                    if ($rentalDetails['accessKey'] !== $unit['accessKey']['assignedKey']) {
                        throw new Exception('Invalid access key');
                    }
                    $now = new UTCDateTime();
                    if (!isset($unit['accessKey']['expiresAt']) || $now > $unit['accessKey']['expiresAt']) {
                        throw new Exception('Access key has expired');
                    }
                    $accessKey = $unit['accessKey']['assignedKey'];
                } else {
                    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
                    $accessKey = '';
                    for ($i = 0; $i < 6; $i++) {
                        $accessKey .= $characters[random_int(0, strlen($characters) - 1)];
                    }
                    $now = new UTCDateTime();
                    $expiresAt = new UTCDateTime($now->toDateTime()->modify("+12 hours")->getTimestamp() * 1000);

                    $this->unitCollection->updateOne(
                        ['_id' => $unit['_id']],
                        [
                            '$set' => [
                                'accessKey.isShared' => true,
                                'accessKey.assignedKey' => $accessKey,
                                'accessKey.createdAt' => $now,
                                'accessKey.expiresAt' => $expiresAt
                            ]
                        ]
                    );
                }
            } else {}

            // Handle parking
            $rawParking = $rentalDetails['parking'] ?? null;

            if (is_string($rawParking)) {
                $decoded = json_decode($rawParking, true);
                $parking = is_array($decoded) ? $decoded : ['hasParking' => false, 'fee' => 0.0];
            } elseif (is_array($rawParking)) {
                $parking = $rawParking;
            } else {
                $parking = ['hasParking' => false, 'fee' => 0.0];
            }
            if (!isset($parking['fee']) || !is_numeric($parking['fee'])) {
                $parking['fee'] = 50.0;
            }
            
            // Handle shuttle (based on user's hasShuttle)
            $shuttle = [
                'hasShuttle' => $user['hasShuttle'] ?? false,
                'fee' => ($user['hasShuttle'] ?? false) ? ($rentalDetails['shuttlePrice'] ?? 50.0) : 0.0
            ];

            // Handle subunit + total price
            $priceEntries = [];
            $priceName = 'default';
            $priceValue = 0.0;
            $selectedSubUnit = $rentalDetails['selectedSubUnit'] ?? null;
            if (is_string($selectedSubUnit)) {
                $selectedSubUnit = json_decode($selectedSubUnit, true);
            }
            if (isset($selectedSubUnit['price'])) {
                $price = $selectedSubUnit['price'];

                if (is_array($price)) {
                    // Case 1: associative array with name/price keys
                    if (array_key_exists('name', $price) && array_key_exists('price', $price)) {
                        $priceName  = $price['name'] ?? 'default';
                        $priceValue = floatval($price['price'] ?? 0);
                    }
                    // Case 2: array of associative arrays (e.g. multiple price entries)
                    elseif (isset($price[0]) && is_array($price[0]) && array_key_exists('price', $price[0])) {
                        $priceEntries = $price[0];
                        $priceName  = $priceEntries['name'] ?? 'default';
                        $priceValue = floatval($priceEntries['price'] ?? 0);
                    }
                    // Case 3: array of scalars
                    else {
                        $priceValue = floatval($price[0] ?? 0);
                        $priceName  = 'default';
                    }
                } else {
                    // Case 4: plain scalar
                    $priceValue = floatval($price);
                    $priceName  = 'default';
                }
            }
            $subUnitData = [
                'type' => $selectedSubUnit['type'],
                'roomType' => $selectedSubUnit['roomType'] ?? null,
                'bedType' => $selectedSubUnit['bedType'] ?? null,
                'price' => [
                    'name' => $priceName,
                    'price' => $priceValue
                ],
                'isAvailable' => $selectedSubUnit['isAvailable'] ?? true
            ];
            $totalRentalPrice = $subUnitData['price']['price'] 
                + ($parking['hasParking'] ? $parking['fee'] : 0.0) 
                + ($shuttle['hasShuttle'] ? $shuttle['fee'] : 0.0);
            $signatureData = null;
            if (!empty($signatureImage)) {
                $signatureFiles = is_array($signatureImage) ? $signatureImage : [$signatureImage];
                $signatureUploads = array_map(function ($file) {
                    if ($file->getError() !== UPLOAD_ERR_OK)
                        throw new Exception('Invalid signature image upload');
                    $uploaded = $this->localFileHelper->uploadImage($file);
                    return [
                        'imageUrl' => (string) $uploaded['imageUrl'],
                        'fileId' => (string) $uploaded['fileId'],
                        '_id' => new ObjectId()
                    ];
                }, $signatureFiles);
                $signatureData = $signatureUploads[0];
            }

            $guardianSignatureData = null;
            if (!empty($guardianSignatureImage)) {
                $guardianFiles = is_array($guardianSignatureImage) ? $guardianSignatureImage : [$guardianSignatureImage];
                $guardianUploads = array_map(function ($file) {
                    if ($file->getError() !== UPLOAD_ERR_OK)
                        throw new Exception('Invalid guardian signature image upload');
                    $uploaded = $this->localFileHelper->uploadImage($file);
                    return [
                        'imageUrl' => (string) $uploaded['imageUrl'],
                        'fileId' => (string) $uploaded['fileId'],
                        '_id' => new ObjectId()
                    ];
                }, $guardianFiles);
                $guardianSignatureData = $guardianUploads[0];
            }

            // Create Rental
            $unitTypeForRental = $subUnitData['roomType'] ?? $subUnitData['bedType'] ?? $unit['unitType'];
            
            $unitYear = isset($unit['unitYear']) ? (int) $unit['unitYear'] : null;
            if ($unitYear === null && isset($rentalDetails['unitYear'])) {
                $unitYear = (int) $rentalDetails['unitYear'];
            }
            error_log('unitYear resolved to: ' . var_export($unitYear, true));
            $rental = new Rental(
                rentalStartDate: $rentalDetails['rentalStartDate'] ?? null,
                rentalEndDate: $rentalDetails['rentalEndDate'] ?? null,
                rentalPrice: $totalRentalPrice,
                unit: $unit['_id'],
                unitType: $unitTypeForRental,
                user: $user['_id'],
                accessKey: $accessKey ?? $unit['accessKey']['assignedKey'] ?? null,
                parking: $parking,
                shuttle: $shuttle,
                signature: $signatureData,
                guardianSignature: $guardianSignatureData,
                selectedSubUnits: $subUnitData,
                unitYear: $unitYear,
            );
            $result = $this->rentalCollection->insertOne($rental->toArray());
            $newRental = $this->rentalCollection->findOne(['_id' => $result->getInsertedId()]);

            $this->userCollection->updateOne(
                ['_id' => $user['_id']],
                ['$push' => ['rentals' => $newRental['_id']]]
            );
            if (isset($unit['reservedBy'])) {
                $this->unitCollection->updateOne(
                    ['_id' => $unit['_id']],
                    ['$unset' => ['reservedBy' => '', 'reservedAt' => '']]
                );
            }
            return [
                'rental' => $newRental,
                'accessKey' => $accessKey
            ];
        } catch (Exception $e) {
            error_log('RentalService error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function findRentalByIdService($rentalId)
    {
        try {
            $rental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rental) {
                throw new Exception('Rental not found');
            }
            return $rental;
        } catch (Exception $e) {
            error_log('FindRentalByIdService error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function findAllRentalsService(): array
    {
        try {
            $rentals = $this->rentalCollection->find();
            $results = [];
            foreach ($rentals as $doc) {
                $rental = [
                    '_id' => (string) $doc['_id'],
                    'applicationDate' => $this->safeDateFormat($doc['applicationDate'] ?? null),
                    'status' => $doc['status'] ?? null,
                    'rentalStartDate' => $this->safeDateFormat($doc['rentalStartDate'] ?? null),
                    'rentalEndDate' => $this->safeDateFormat($doc['rentalEndDate'] ?? null),
                    'earlyEndDate' => $this->safeDateFormat($doc['earlyEndDate'] ?? null),
                    'rentalPrice' => $doc['rentalPrice'] ?? null,
                    'trafalgarId' => $doc['trafalgarId'] ?? null,
                    'unitYear' => $doc['unitYear'] ?? null,
                    'unit' => (string) $doc['unit'] ?? null,
                    'unitType' => $doc['unitType'] ?? null,
                    'renewed' => $doc['renewed'] ?? null,
                    'renewedFromUnit' => isset($doc['renewedFromUnit']) ? (string) $doc['renewedFromUnit'] : null,
                    'renewedToUnit' => isset($doc['renewedToUnit']) ? (string) $doc['renewedToUnit'] : null,
                    'renewalHistory' => isset($doc['renewalHistory']) ? $this->formatRenewalHistory($doc['renewalHistory']) : [],
                    'user' => (string) $doc['user'] ?? null,
                    'accessKey' => $doc['accessKey'] ?? null,
                    'parking' => [
                        'hasParking' => $doc['parking']['hasParking'] ?? false,
                        'fee' => $doc['parking']['fee'] ?? 0.0,
                    ],
                    'shuttle' => [
                        'hasShuttle' => $doc['shuttle']['hasShuttle'] ?? false,
                        'fee' => $doc['shuttle']['fee'] ?? 0.0,
                    ],
                ];
                $rental['payerData'] = [
                    'firstName' => $doc['payerData']['firstName'] ?? null,
                    'lastName' => $doc['payerData']['lastName'] ?? null,
                    'email' => $doc['payerData']['email'] ?? null,
                    'idNumber' => $doc['payerData']['idNumber'] ?? null,
                    'bankName' => $doc['payerData']['bankName'] ?? null,
                    'salary' => $doc['payerData']['salary'] ?? 0.0,
                    'score' => $doc['payerData']['score'] ?? 0,
                    'isValidated' => $doc['payerData']['isValidated'] ?? false,
                ];
                $rental['documents'] = isset($doc['documents']) ? array_map(function ($document) {
                    return [
                        'documentUrl' => $document['documentUrl'] ?? null,
                        'fileId' => $document['fileId'] ?? null,
                        'uploadDate' => $this->safeDateFormat($document['uploadDate'] ?? null),
                        'docType' => $document['docType'] ?? null,
                        '_id' => isset($document['_id']) ? (string) $document['_id'] : null
                    ];
                }, $doc['documents']->getArrayCopy()) : [];
                $rental['signingTokens'] = $doc['signingTokens'] ?? null;
                $rental['selectedSubUnits'] = [
                    'type' => $doc['selectedSubUnits']['type'] ?? null,
                    'roomType' => $doc['selectedSubUnits']['roomType'] ?? null,
                    'bedType' => $doc['selectedSubUnits']['bedType'] ?? null,
                    'price' => isset($doc['selectedSubUnits']['price']['price'])
                        ? [
                            'name' => $doc['selectedSubUnits']['price']['name'] ?? 'default',
                            'price' => floatval($doc['selectedSubUnits']['price']['price'])
                        ]
                        : (float) ($doc['selectedSubUnits']['price'] ?? 0.0),
                    'isAvailable' => $doc['selectedSubUnits']['isAvailable'] ?? true,
                ];
                $results[] = $rental;
            }
            return $results;
        } catch (Exception $e) {
            error_log('FindAllRentalsService error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function findAllMyRentalsService($userId)
    {
        try {
            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                throw new Exception('User not found');
            }
            $rentalIds = $user['rentals'] ?? [];
            if (empty($rentalIds)) {
                return [];
            }
            $myRentals = $this->rentalCollection->find(['_id' => ['$in' => $user['rentals']]]);
            $results = [];
            foreach ($myRentals as $doc) {
                $rental = [
                    '_id' => (string) $doc['_id'],
                    'applicationDate' => $this->safeDateFormat($doc['applicationDate'] ?? null),
                    'status' => $doc['status'] ?? null,
                    'rentalStartDate' => $this->safeDateFormat($doc['rentalStartDate'] ?? null),
                    'rentalEndDate' => $this->safeDateFormat($doc['rentalEndDate'] ?? null),
                    'earlyEndDate' => $this->safeDateFormat($doc['earlyEndDate'] ?? null),
                    'rentalPrice' => $doc['rentalPrice'] ?? null,
                    'trafalgarId' => $doc['trafalgarId'] ?? null,
                    'unitYear' => $doc['unitYear'] ?? null,
                    'renewed' => $doc['renewed'] ?? null,
                    'renewedFromUnit' => isset($doc['renewedFromUnit']) ? (string) $doc['renewedFromUnit'] : null,
                    'renewedToUnit' => isset($doc['renewedToUnit']) ? (string) $doc['renewedToUnit'] : null,
                    'renewalHistory' => isset($doc['renewalHistory']) ? $this->formatRenewalHistory($doc['renewalHistory']) : [],
                    'unit' => (string) $doc['unit'] ?? null,
                    'unitType' => $doc['unitType'] ?? null,
                    'user' => (string) $doc['user'] ?? null,
                    'accessKey' => $doc['accessKey'] ?? null,
                    'parking' => [
                        'hasParking' => $doc['parking']['hasParking'] ?? false,
                        'fee' => $doc['parking']['fee'] ?? 0.0,
                    ],
                    'shuttle' => [
                        'hasShuttle' => $doc['shuttle']['hasShuttle'] ?? false,
                        'fee' => $doc['shuttle']['fee'] ?? 0.0,
                    ],
                ];
                $rental['payerData'] = [
                    'firstName' => $doc['payerData']['firstName'] ?? null,
                    'lastName' => $doc['payerData']['lastName'] ?? null,
                    'email' => $doc['payerData']['email'] ?? null,
                    'idNumber' => $doc['payerData']['idNumber'] ?? null,
                    'bankName' => $doc['payerData']['bankName'] ?? null,
                    'salary' => $doc['payerData']['salary'] ?? 0.0,
                    'score' => $doc['payerData']['score'] ?? 0,
                    'isValidated' => $doc['payerData']['isValidated'] ?? false,
                ];
                $rental['documents'] = isset($doc['documents']) ? array_map(function ($document) {
                    return [
                        'documentUrl' => $document['documentUrl'] ?? null,
                        'fileId' => $document['fileId'] ?? null,
                        'uploadDate' => $this->safeDateFormat($document['uploadDate'] ?? null),
                        'docType' => $document['docType'] ?? null,
                        '_id' => isset($document['_id']) ? (string) $document['_id'] : null
                    ];
                }, $doc['documents']->getArrayCopy()) : [];
                $rental['signingTokens'] = $doc['signingTokens'] ?? null;
                $rental['selectedSubUnits'] = [
                    'type' => $doc['selectedSubUnits']['type'] ?? null,
                    'roomType' => $doc['selectedSubUnits']['roomType'] ?? null,
                    'bedType' => $doc['selectedSubUnits']['bedType'] ?? null,
                    'price' => isset($doc['selectedSubUnits']['price']['price'])
                        ? [
                            'name' => $doc['selectedSubUnits']['price']['name'] ?? 'default',
                            'price' => floatval($doc['selectedSubUnits']['price']['price'])
                        ]
                        : (float) ($doc['selectedSubUnits']['price'] ?? 0.0),
                    'isAvailable' => $doc['selectedSubUnits']['isAvailable'] ?? true,
                ];
                $results[] = $rental;
            }
            return $results;
        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function verifyAndSavePayerService(string $rentalId, array $rentalData): array
    {
        try {
            $rental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rental) {
                throw new Exception('Rental not found');
            }
            $score = $this->ScoreApi->scorePayerData($rentalData['payerData'] ?? []) ?? 0; // Default to 0
            $isEligible = $score >= 60;

            $payerData = $rental['payerData'] ?? [];
            $payerData['isValidated'] = $isEligible;
            $payerData['score'] = $score;

            $payerData['firstName'] = $rentalData['payerData']['firstName'] ?? '';
            $payerData['lastName'] = $rentalData['payerData']['lastName'] ?? '';
            $payerData['email'] = $rentalData['payerData']['email'] ?? '';
            $payerData['idNumber'] = (string) ($rentalData['payerData']['idNumber'] ?? '');
            $payerData['salary'] = (float) $rentalData['payerData']['salary'] ?? 0;
            $payerData['bankName'] = is_array($rentalData['payerData']['bankName'] ?? null) ?
                ($rentalData['payerData']['bankName']['value'] ?? '') :
                ($rentalData['payerData']['bankName'] ?? '');
            $this->rentalCollection->updateOne(
                ['_id' => new ObjectId($rentalId)],
                ['$set' => ['payerData' => $payerData]]
            );
            return ['isValidated' => $isEligible, 'score' => $score];
        } catch (Exception $e) {
            error_log("Error in verifyAndSavePayerService: " . $e->getMessage());
            throw new Exception('Failed to verify payer data: ' . $e->getMessage());
        }
    }

    // ─── UPLOAD DOCUMENT FUNCTIONS ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
    
    public function uploadRentalDocsService(string $userId, array $rentalDocs) {
        try {
            $pendingRental = $this->rentalCollection->findOne([
                'user' => new MongoDB\BSON\ObjectId($userId),
                'status' => 'Pending'
            ]);
            if (!$pendingRental) {
                throw new Exception('No pending rental found for this user');
            }

            $rentalId = (string) $pendingRental['_id'];
            if (!empty($rentalDocs)) {
                $uploadedDocuments = [];
                foreach ($rentalDocs as $file) {
                    $uploadResult = $this->localFileHelper->uploadDocument($file);
                    $uploadedDocuments[] = array_merge(
                        $uploadResult,
                        ['docType' => $_POST['type'] ?? null]
                    );
                }

                $updateResult = $this->rentalCollection->updateOne(
                    ['_id' => new MongoDB\BSON\ObjectId($rentalId)],
                    [
                        '$push' => [
                            'documents' => [
                                '$each' => array_map(function ($doc) use ($rentalDocs) {
                                    return [
                                        'documentUrl' => $doc['documentUrl'],
                                        'fileId'      => $doc['fileId'],
                                        'uploadDate'  => new MongoDB\BSON\UTCDateTime(),
                                        'docType'     => $doc['docType'] ?? null,
                                    ];
                                }, $uploadedDocuments)
                            ]
                        ]
                    ]
                );

                if ($updateResult->getModifiedCount() === 0) {
                    throw new Exception('Failed to update rental documents');
                }
            }
            $deleteResult = $this->applicationDraftCollection->deleteOne([
                'userId' => new MongoDB\BSON\ObjectId($userId),
                'status' => 'draft'
            ]);
            if ($deleteResult->getDeletedCount() > 0) {
                error_log("Draft deleted for user: {$userId} after document upload");
            }
            return $this->userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    public function clearAllRentalDocsService(string $rentalId) {
        try {
            $rental = $this->rentalCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($rentalId)]);
            if (!$rental) {
                throw new Exception('Rental not found');
            }
            if (isset($rental['documents']) && is_array($rental['documents'])) {
                foreach ($rental['documents'] as $doc) {
                    if (isset($doc['fileId'])) {
                        $this->localFileHelper->deleteDocument($doc['fileId']);
                    }
                }
            }
            $updateResult = $this->rentalCollection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($rentalId)],
                ['$set' => ['documents' => []]]
            );
            $userId = (string) $rental['user'];
            $deleteResult = $this->applicationDraftCollection->deleteOne([
                'userId' => new ObjectId($userId),
                'rentalId' => new ObjectId($rentalId),
                'status' => 'draft'
            ]);
            if ($deleteResult->getDeletedCount() > 0) {
                error_log("Draft deleted for user: {$userId} and rental: {$rentalId} after clearing documents");
            }
            return $this->rentalCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($rentalId)]);
        } catch (Exception $e) {
            throw $e;
        }
    }
}