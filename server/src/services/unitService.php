<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
// require_once __DIR__ . '/../utils/imageKit.php';
require_once __DIR__ . '/../utils/LocalFileHelper.php';
require_once __DIR__ . '/../../src/services/rentalService.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class UnitService
{
    private $unitCollection;
    private $rentalCollection;
    private $userCollection;
    private $localFileHelper;
    private $rentalService;

    public function __construct()
    {
        $db = Database::getDb();
        $this->unitCollection = $db->Unit;
        $this->rentalCollection = $db->Rental;
        $this->userCollection = $db->User;
        $this->localFileHelper = new LocalFileHelper();
        $this->rentalService = new RentalService();
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

    protected function unitChecks($unit)
    {
        error_log("unitChecks called for unit: " . json_encode($unit));
        $updateData = [];

        // ============================================================
        // 1. YEAR-BASED CLEANUP (auto-unlock expired units)
        // ============================================================
        $currentYear = (int) date('Y');
        $unitYear = $unit['unitYear'] ?? $currentYear;
        
        if ($unitYear < $currentYear) {
            // Check if there are any ACTIVE rentals for this unit
            $activeRental = $this->rentalCollection->findOne([
                'unit' => new ObjectId($unit['_id']),
                'status' => 'Active'
            ]);
            
            // If no active rentals, unlock everything
            if (!$activeRental) {
                // Unlock all sub-units
                if (!empty($unit['subUnits'])) {
                    $subUnitsArray = $unit['subUnits'] instanceof \MongoDB\Model\BSONArray
                        ? $unit['subUnits']->getArrayCopy()
                        : (array) $unit['subUnits'];
                    
                    foreach ($subUnitsArray as $key => $subUnit) {
                        $this->unitCollection->updateOne(
                            ['_id' => new ObjectId($unit['_id'])],
                            [
                                '$set' => [
                                    "subUnits.{$key}.isAvailable" => true,
                                    "subUnits.{$key}.reservedBy" => null,
                                    "subUnits.{$key}.reservedAt" => null
                                ],
                                '$unset' => [
                                    "subUnits.{$key}.extended" => '',
                                    "subUnits.{$key}.extendedToYear" => ''
                                ]
                            ]
                        );
                    }
                }
                
                // Reset unit
                $updateData['unitStatus'] = 'Available';
                $updateData['currentOccupants'] = 0;
                $updateData['genderAssignment'] = null;
                
                error_log("Unit {$unit['unitNumber']} (year {$unitYear}) has been unlocked");
                
                if (!empty($updateData)) {
                    $this->unitCollection->updateOne(
                        ['_id' => new ObjectId($unit['_id'])],
                        ['$set' => $updateData]
                    );
                }
                return; // Exit early
            }
        }

        // ============================================================
        // 2. COUNT OCCUPIED SUB-UNITS
        // ============================================================
        $occupiedCount = 0;
        if (!empty($unit['subUnits'])) {
            $subUnitsArray = $unit['subUnits'] instanceof \MongoDB\Model\BSONArray
                ? $unit['subUnits']->getArrayCopy()
                : (array) $unit['subUnits'];
            
            foreach ($subUnitsArray as $subUnit) {
                if ($subUnit instanceof \MongoDB\Model\BSONDocument) {
                    $subUnit = $subUnit->getArrayCopy();
                }
                $val = $subUnit['isAvailable'] ?? null;
                $isAvailable = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isAvailable === false) {
                    $occupiedCount++;
                }
            }
        } else {
            $occupiedCount = $unit['currentOccupants'] ?? 0;
            error_log("WARNING: Unit {$unit['unitNumber']} has no subUnits array, keeping currentOccupants = {$occupiedCount}");
        }
        
        $updateData['currentOccupants'] = $occupiedCount;

        // ============================================================
        // 3. SET UNIT STATUS
        // ============================================================
        $capacity = $unit['unitOccupants'] ?? 0;
        $updateData['unitStatus'] = ($occupiedCount >= $capacity) ? 'Occupied' : 'Available';

        // ============================================================
        // 4. GENDER ASSIGNMENT
        // ============================================================
        // If unit is empty → clear gender
        if ($occupiedCount == 0) {
            $updateData['genderAssignment'] = null;
        }
        // If exactly 1 occupant → assign gender from that user
        else if ($occupiedCount === 1 && empty($unit['genderAssignment'])) {
            $rental = $this->rentalCollection->findOne([
                'unit' => new ObjectId($unit['_id']),
                'status' => 'Active'
            ]);
            if ($rental && isset($rental['user'])) {
                $user = $this->userCollection->findOne(['_id' => $rental['user']]);
                if ($user && isset($user['gender'])) {
                    $updateData['genderAssignment'] = $user['gender'];
                }
            }
        }

        // ============================================================
        // 5. ENSURE GENDER CONSISTENCY ACROSS SAME UNIT NUMBER
        // ============================================================
        if (!empty($updateData['genderAssignment'])) {
            $existingUnit = $this->unitCollection->findOne([
                'unitNumber' => $unit['unitNumber'],
                '_id' => ['$ne' => new ObjectId($unit['_id'])]
            ]);
            if ($existingUnit && isset($existingUnit['genderAssignment']) && 
                $existingUnit['genderAssignment'] !== $updateData['genderAssignment']) {
                throw new Exception("Gender restriction: This unit is only available for " . $existingUnit['genderAssignment'] . "s.");
            }
        }

        // ============================================================
        // 6. ENSURE UNIT YEAR IS SET
        // ============================================================
        if (!isset($unit['unitYear']) || $unit['unitYear'] === null) {
            $updateData['unitYear'] = 2026;
            error_log("Added unitYear = 2026 to unit: {$unit['unitNumber']}");
        }

        // ============================================================
        // 7. APPLY UPDATES
        // ============================================================
        if (!empty($updateData)) {
            $this->unitCollection->updateOne(
                ['_id' => new ObjectId($unit['_id'])],
                ['$set' => $updateData]
            );
        }
    }

    public function runUnitChecks($unit)
    {
        return $this->unitChecks($unit);
    }

    protected function fixRentalDatesTo2026()
    {
        try {
            $rentalsToFix = $this->rentalCollection->find([
                '$or' => [
                    ['rentalStartDate' => [
                        '$gte' => new UTCDateTime(strtotime('2027-01-01') * 1000),
                        '$lt' => new UTCDateTime(strtotime('2028-01-01') * 1000)
                    ]],
                    ['rentalEndDate' => [
                        '$gte' => new UTCDateTime(strtotime('2027-01-01') * 1000),
                        '$lt' => new UTCDateTime(strtotime('2028-01-01') * 1000)
                    ]]
                ]
            ]);
            $fixedCount = 0;
            foreach ($rentalsToFix as $rental) {
                try {
                    $rentalId = (string) $rental['_id'];
                    $updateData = [];

                    // Fix start date: keep month/day, change year to 2026
                    if (isset($rental['rentalStartDate'])) {
                        $date = $rental['rentalStartDate']->toDateTime();
                        $year = (int) $date->format('Y');
                        if ($year === 2027) {
                            $month = $date->format('m');
                            $day = $date->format('d');
                            $newDate = new DateTime("2026-{$month}-{$day}");
                            $updateData['rentalStartDate'] = new UTCDateTime($newDate->getTimestamp() * 1000);
                        }
                    }
                    // Fix end date: keep month/day, change year to 2026
                    if (isset($rental['rentalEndDate'])) {
                        $date = $rental['rentalEndDate']->toDateTime();
                        $year = (int) $date->format('Y');
                        if ($year === 2027) {
                            $month = $date->format('m');
                            $day = $date->format('d');
                            $newDate = new DateTime("2026-{$month}-{$day}");
                            $updateData['rentalEndDate'] = new UTCDateTime($newDate->getTimestamp() * 1000);
                        }
                    }
                    if (!empty($updateData)) {
                        $this->rentalCollection->updateOne(
                            ['_id' => $rental['_id']],
                            ['$set' => $updateData]
                        );
                        $fixedCount++;
                        error_log("Fixed rental {$rentalId} dates to 2026");
                    }
                } catch (Exception $e) {
                    error_log("Failed to fix rental {$rentalId}: " . $e->getMessage());
                }
            }
            error_log("Fixed {$fixedCount} rentals to 2026");
        } catch (Exception $e) {
            error_log("Error in fixRentalDatesTo2026: " . $e->getMessage());
        }
    }

    // ─── HANDLERS ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function createUnitService(array $unitDetails, array $unitImages)
    {
        try {
            if (!empty($unitDetails['subUnits']) && is_string($unitDetails['subUnits'])) {
                $unitDetails['subUnits'] = json_decode($unitDetails['subUnits'], true);
            }
            $unitYear = isset($unitDetails['unitYear']) && is_numeric($unitDetails['unitYear'])
                ? (int) $unitDetails['unitYear']
                : 2026;
            $unitDetails['unitYear'] = $unitYear;
            if ($this->unitCollection->findOne([
                'unitNumber' => $unitDetails['unitNumber'],
                'unitYear' => $unitYear
            ])) {
                throw new Exception('Unit already exists for this year');
            }

            $imageData = array_map(function ($file) {
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    throw new Exception('Invalid image upload');
                }
                $uploaded = $this->localFileHelper->uploadImage($file);

                return [
                    'imageUrl' => (string) $uploaded['imageUrl'],
                    'fileId' => (string) $uploaded['fileId'],
                    '_id' => new ObjectId()
                ];
            }, $unitImages['images'] ?? []);

            // Process subUnits if provided
            $subUnits = array_map(function ($subUnit) {
                $prices = [];
                if (isset($subUnit['price'])) {
                    if (is_array($subUnit['price'])) {
                        // If price is already array, check if it's array of floats or array of named prices
                        if (!empty($subUnit['price']) && is_array($subUnit['price']) && array_key_exists('price', $subUnit['price'][0])) {
                            foreach ($subUnit['price'] as $priceEntry) {
                                $prices[] = [
                                    'name' => $priceEntry['name'] ?? 'default',
                                    'price' => floatval($priceEntry['price'] ?? 0)
                                ];
                            }
                        } else {
                            foreach ($subUnit['price'] as $priceVal) {
                                $prices[] = ['name' => 'default', 'price' => floatval($priceVal)];
                            }
                        }
                    } else {
                        $prices[] = ['name' => 'default', 'price' => floatval($subUnit['price'])];
                    }
                } else {
                    $prices[] = ['name' => 'default', 'price' => 0];
                }
                return [
                    'type' => $subUnit['type'] ?? 'room',
                    'roomType' => $subUnit['roomType'] ?? null,
                    'bedType' => $subUnit['bedType'] ?? null,
                    'price' => $prices,
                    'isAvailable' => true
                ];
            }, $unitDetails['subUnits'] ?? []);

            // Derive unitOccupants from subUnits count
            $unitDetails['unitOccupants'] = count($subUnits);
            $unitDetails['unitNumber'] = (string) $unitDetails['unitNumber'];
            $unitDetails['floorLevel'] = (string) $unitDetails['floorLevel'];
            $unitDetails['unitType'] = (string) $unitDetails['unitType'];
            $unitDetails['unitOccupants'] = (int) $unitDetails['unitOccupants'];
            $unitDetails['unitDescription'] = (string) $unitDetails['unitDescription'];

            if (!isset($unitDetails['unitPrice']) || $unitDetails['unitPrice'] === '') {
                $allPrices = array_merge(...array_map(function ($subUnit) {
                    return array_map(fn($p) => floatval($p['price'] ?? 0), $subUnit['price']);
                }, $subUnits));

                $unitDetails['unitPrice'] = count($allPrices) > 0
                    ? min($allPrices)
                    : 0;
            } else {
                $unitDetails['unitPrice'] = (float) $unitDetails['unitPrice'];
            }

            // Create Unit instance
            $unit = new Unit(
                unitNumber: $unitDetails['unitNumber'],
                floorLevel: $unitDetails['floorLevel'],
                unitType: $unitDetails['unitType'],
                unitOccupants: $unitDetails['unitOccupants'],
                unitDescription: $unitDetails['unitDescription'],
                unitPrice: $unitDetails['unitPrice'],
                unitYear: $unitDetails['unitYear'] ?? null,
                subUnits: $subUnits,
                unitStatus: 'Available',
                genderAssignment: $unitDetails['genderAssignment'] ?? null,
                currentOccupants: 0,
                images: $imageData,
                rentedHistory: [],
                accessKey: ['isShared' => null, 'assignedKey' => null],
            );

            $result = $this->unitCollection->insertOne($unit->toArray());
            return $this->unitCollection->findOne(['_id' => $result->getInsertedId()]);
        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function findUnitByIdService($unitId)
    {
        try {
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }
            $this->unitChecks($unit);
            $updatedUnit = $this->unitCollection->findOne(['_id' => $unit['_id']]);
            return $updatedUnit ?: $unit;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findAllUnitsService(): array
    {
        try {
            $units = $this->unitCollection->find();
            $results = [];
            foreach ($units as $doc) {
                $unit = [
                    '_id' => (string) $doc['_id'],
                    'unitNumber' => $doc['unitNumber'] ?? null,
                    'floorLevel' => $doc['floorLevel'] ?? null,
                    'unitType' => $doc['unitType'] ?? null,
                    'unitOccupants' => $doc['unitOccupants'] ?? null,
                    'currentOccupants' => $doc['currentOccupants'] ?? null,
                    'unitDescription' => $doc['unitDescription'] ?? null,
                    'unitPrice' => $doc['unitPrice'] ?? null,
                    'unitYear' => $doc['unitYear'] ?? null,
                    'unitStatus' => $doc['unitStatus'] ?? null,
                    'dateCreated' => $this->safeDateFormat($doc['dateCreated'] ?? null),
                    'genderAssignment' => $doc['genderAssignment'] ?? null,
                    'reservedBy' => isset($doc['reservedBy']) ? (string) $doc['reservedBy'] : null,
                    'reservedAt' => isset($doc['reservedAt']) ? $this->safeDateFormat($doc['reservedAt']) : null
                ];
                if (!empty($doc['images']) && $doc['images'] instanceof \MongoDB\Model\BSONArray) {
                    $unit['images'] = [];
                    foreach ($doc['images'] as $image) {
                        $unit['images'][] = [
                            'imageUrl' => $image['imageUrl'] ?? null,
                            'fileId' => $image['fileId'] ?? null,
                            '_id' => isset($image['_id']) ? (string) $image['_id'] : null
                        ];
                    }
                } else {
                    $unit['images'] = $doc['images'] ?? null;
                }
                $unit['rentedHistory'] = isset($doc['rentedHistory'])
                    ? array_map(function ($id) {
                        return (string) $id;
                    }, $doc['rentedHistory']->getArrayCopy())
                    : [];
                $unit['accessKey'] = [
                    'isShared' => $doc['accessKey']['isShared'] ?? false,
                    'assignedKey' => $doc['accessKey']['assignedKey'] ?? 0,
                    'createdAt' => $doc['accessKey']['createdAt'] ?? null,
                    'expiresAt' => $doc['accessKey']['expiresAt'] ?? null
                ];
                $unit['subUnits'] = [];

                // RUN UNIT CHECK AND UPDATE
                $doc = $this->unitCollection->findOne(['_id' => new ObjectId($unit['_id'])]);
                if ($doc) {
                    $this->unitChecks($doc);
                } else {
                    error_log("Unit not found for id {$unit['_id']}");
                }
                if (!empty($doc['subUnits'])) {
                    $subUnitsArray = $doc['subUnits'] instanceof \MongoDB\Model\BSONArray
                        ? $doc['subUnits']->getArrayCopy()
                        : (array) $doc['subUnits'];

                    foreach ($subUnitsArray as $sub) {
                        $pricesArray = [];
                        if (isset($sub['price']) && is_array($sub['price'])) {
                            foreach ($sub['price'] as $priceEntry) {
                                if ($priceEntry instanceof \MongoDB\Model\BSONDocument) {
                                    $priceEntry = $priceEntry->getArrayCopy();
                                }
                                $pricesArray[] = [
                                    'name' => $priceEntry['name'] ?? 'default',
                                    'price' => floatval($priceEntry['price'] ?? 0),
                                ];
                            }
                        } elseif (isset($sub['price'])) {
                            if ($sub['price'] instanceof \MongoDB\Model\BSONArray) {
                                $pricesArray = array_map(function ($p) {
                                    if ($p instanceof \MongoDB\Model\BSONDocument) {
                                        $p = $p->getArrayCopy();
                                    }
                                    if (is_array($p) && array_key_exists('price', $p)) {
                                        return [
                                            'name' => $p['name'] ?? 'default',
                                            'price' => floatval($p['price'] ?? 0)
                                        ];
                                    }
                                    return ['name' => 'default', 'price' => floatval($p)];
                                }, $sub['price']->getArrayCopy());
                            } elseif (is_array($sub['price'])) {
                                $pricesArray = array_map(function ($p) {
                                    if ($p instanceof \MongoDB\Model\BSONDocument) {
                                        $p = $p->getArrayCopy();
                                    }
                                    if (is_array($p) && array_key_exists('price', $p)) {
                                        return [
                                            'name' => $p['name'] ?? 'default',
                                            'price' => floatval($p['price'] ?? 0)
                                        ];
                                    }
                                    return ['name' => 'default', 'price' => floatval($p)];
                                }, $sub['price']);
                            } else {
                                $pricesArray[] = ['name' => 'default', 'price' => floatval($sub['price'])];
                            }
                        } else {
                            $pricesArray[] = ['name' => 'default', 'price' => 0];
                        }
                        $unit['subUnits'][] = [
                            'type' => $sub['type'] ?? 'room',
                            'roomType' => $sub['roomType'] ?? null,
                            'bedType' => $sub['bedType'] ?? null,
                            'price' => $pricesArray,
                            'isAvailable' => $sub['isAvailable'] ?? true,
                            'reservedBy' => isset($sub['reservedBy']) ? (string) $sub['reservedBy'] : null,
                            'reservedAt' => isset($sub['reservedAt']) ? $this->safeDateFormat($sub['reservedAt']) : null
                        ];
                    }
                }
                $updatedDoc = $this->unitCollection->findOne(['_id' => $doc['_id']]);
                if ($updatedDoc) {
                    $unit['unitStatus'] = $updatedDoc['unitStatus'] ?? $unit['unitStatus'];
                    $unit['accessKey'] = $updatedDoc['accessKey'] ?? $unit['accessKey'];
                    $unit['genderAssignment'] = $updatedDoc['genderAssignment'] ?? $unit['genderAssignment'];
                    $unit['reservedBy'] = isset($updatedDoc['reservedBy']) ? (string) $updatedDoc['reservedBy'] : $unit['reservedBy'];
                    $unit['reservedAt'] = isset($updatedDoc['reservedAt']) ? $this->safeDateFormat($updatedDoc['reservedAt']) : $unit['reservedAt'];
                }
                $results[] = $unit;
            }
            return $results;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function updateUnitService($unitId, $unitDetails, $unitImages = [])
    {
        try {
            if (!empty($unitDetails['subUnits']) && is_string($unitDetails['subUnits'])) {
                $decoded = json_decode($unitDetails['subUnits'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid subUnits JSON: ' . json_last_error_msg());
                }
                $newSubUnits = is_array($decoded) ? $decoded : [];
            } else {
                $newSubUnits = $unitDetails['subUnits'] ?? [];
            }
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }
            $existingSubUnits = $unit['subUnits'] ?? [];

            $normalizedSubUnits = array_map(function ($subUnit, $index) use ($existingSubUnits) {
                $existing = $existingSubUnits[$index] ?? [];
                $prices = [];
                if (isset($subUnit['price']) && is_array($subUnit['price'])) {
                    foreach ($subUnit['price'] as $priceEntry) {
                        $prices[] = [
                            'name' => $priceEntry['name'] ?? 'default',
                            'price' => floatval($priceEntry['price'] ?? 0)
                        ];
                    }
                } elseif (isset($subUnit['price'])) {
                    if (is_array($subUnit['price'])) {
                        foreach ($subUnit['price'] as $priceVal) {
                            $prices[] = ['name' => 'default', 'price' => floatval($priceVal)];
                        }
                    } else {
                        $prices[] = ['name' => 'default', 'price' => floatval($subUnit['price'])];
                    }
                } else {
                    $prices[] = ['name' => 'default', 'price' => 0];
                }
                return [
                    'type' => $subUnit['type'] ?? $existing['type'] ?? 'room',
                    'roomType' => $subUnit['roomType'] ?? $existing['roomType'] ?? null,
                    'bedType' => $subUnit['bedType'] ?? $existing['bedType'] ?? null,
                    'price' => $prices,
                    'isAvailable' => $subUnit['isAvailable'] ?? $existing['isAvailable'] ?? true,
                    'reservedBy' => $subUnit['reservedBy'] ?? $existing['reservedBy'] ?? null
                ];
            }, $newSubUnits, array_keys($newSubUnits));

            $unitOccupants = count($normalizedSubUnits);

            if (!isset($unitDetails['unitPrice']) || $unitDetails['unitPrice'] === '') {
                $allPrices = array_merge(...array_map(function ($subUnit) {
                    return array_map(fn($p) => floatval($p['price'] ?? 0), $subUnit['price']);
                }, $normalizedSubUnits));
                $unitDetails['unitPrice'] = count($allPrices) > 0 ? min($allPrices) : 0;
            } else {
                $unitDetails['unitPrice'] = (float) $unitDetails['unitPrice'];
            }

            $imageData = [];
            if (!empty($unitImages['images'])) {
                foreach ($unit['images'] ?? [] as $image) {
                    $this->localFileHelper->deleteImage($image['fileId']);
                }
                $imageData = array_map(function ($file) {
                    return $this->localFileHelper->uploadImage($file);
                }, $unitImages['images']);
            } else {
                $imageData = $unit['images'] ?? [];
            }

            $updateData = [
                'unitNumber' => (string) ($unitDetails['unitNumber'] ?? $unit['unitNumber']),
                'floorLevel' => (string) ($unitDetails['floorLevel'] ?? $unit['floorLevel']),
                'unitType' => (string) ($unitDetails['unitType'] ?? $unit['unitType']),
                'unitOccupants' => (!empty($unitDetails['subUnits']) && count($normalizedSubUnits) > 0)
                    ? count($normalizedSubUnits)
                    : $unit['unitOccupants'],
                'unitDescription' => (string) ($unitDetails['unitDescription'] ?? $unit['unitDescription']),
                'unitPrice' => (float) ($unitDetails['unitPrice'] ?? $unit['unitPrice']),
                'unitYear' => isset($unitDetails['unitYear']) ? (int) $unitDetails['unitYear'] : ($unit['unitYear'] ?? null),
                'genderAssignment' => $unitDetails['genderAssignment'] ?? $unit['genderAssignment'] ?? null,
                'images' => !empty($imageData) ? $imageData : ($unit['images'] ?? []),
                'subUnits' => (!empty($unitDetails['subUnits']) && count($normalizedSubUnits) > 0)
                    ? $normalizedSubUnits
                    : $unit['subUnits'],
            ];

            $result = $this->unitCollection->updateOne(
                ['_id' => new ObjectId($unitId)],
                ['$set' => $updateData]
            );
            if ($result->getModifiedCount() === 0) {
                error_log("No documents were modified");
            }
        } catch (Exception $e) {
            error_log('Update failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteUnitService(string $unitId): bool
    {
        try {
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit)
                throw new Exception('Unit not found');
            foreach ($unit['images'] ?? [] as $image) {
                try {
                    $this->localFileHelper->deleteImage($image['fileId']);
                } catch (Exception $e) {
                    error_log("Failed to delete image {$image['fileId']}: " . $e->getMessage());
                }
            }
            $this->unitCollection->deleteOne(['_id' => new ObjectId($unitId)]);
            return true;
        } catch (Exception $e) {
            error_log("Unit deletion failed: " . $e->getMessage());
            throw $e;
        }
    }

    // ─── RESERVE UNITS ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function reserveUnitService($unitId, $userId)
    {
        try {
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }
            if ($unit['currentOccupants'] > 0) {
                throw new Exception('Unit is at full capacity');
            }
            if (isset($unit['reservedBy'])) {
                throw new Exception('Unit is already reserved');
            }
            $this->unitCollection->updateOne(
                ['_id' => new ObjectId($unitId)],
                [
                    '$set' => [
                        'reservedBy' => new ObjectId($userId),
                        'reservedAt' => new UTCDateTime()
                    ]
                ]
            );
            return $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
        } catch (Exception $e) {
            error_log('Reservation error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function cancelReservationService($unitId, $requestingUserId)
    {
        try {
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }
            if (!isset($unit['reservedBy'])) {
                throw new Exception('Unit is not reserved');
            }
            if ((string) $unit['reservedBy'] !== $requestingUserId) {
                throw new Exception('Only the reserving user can cancel this reservation');
            }
            $this->unitCollection->updateOne(
                ['_id' => new ObjectId($unitId)],
                [
                    '$unset' => [
                        'reservedBy' => '',
                        'reservedAt' => ''
                    ]
                ]
            );
            return $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
        } catch (Exception $e) {
            error_log('Reservation cancellation error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function reserveRoomService($unitId, $roomIndex, $userId)
    {
        try {
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }
            if (empty($unit['subUnits']) || !isset($unit['subUnits'][$roomIndex])) {
                throw new Exception('Room not found in unit');
            }
            $room = $unit['subUnits'][$roomIndex];
            if (isset($room['reservedBy'])) {
                throw new Exception('Room is already reserved');
            }

            // Build the update path for nested reservedBy and reservedAt
            $reservedByField = 'subUnits.' . $roomIndex . '.reservedBy';
            $reservedAtField = 'subUnits.' . $roomIndex . '.reservedAt';

            $this->unitCollection->updateOne(
                ['_id' => new ObjectId($unitId)],
                ['$set' => [
                    $reservedByField => new ObjectId($userId),
                    $reservedAtField => new UTCDateTime()
                ]]
            );
            return $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
        } catch (Exception $e) {
            error_log('Room reservation error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function cancelReserveRoomService($unitId, $roomIndex, $requestingUserId)
    {
        try {
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }

            if (empty($unit['subUnits']) || !isset($unit['subUnits'][$roomIndex])) {
                throw new Exception('Room not found in unit');
            }
            $room = $unit['subUnits'][$roomIndex];

            if (!isset($room['reservedBy'])) {
                throw new Exception('Room is not reserved');
            }
            // Check whether the requesting user is the one who reserved the room
            if ((string) $room['reservedBy'] !== $requestingUserId) {
                throw new Exception('Only the reserving user can cancel this reservation');
            }

            $reservedByField = 'subUnits.' . $roomIndex . '.reservedBy';
            $reservedAtField = 'subUnits.' . $roomIndex . '.reservedAt';

            $this->unitCollection->updateOne(
                ['_id' => new ObjectId($unitId)],
                ['$unset' => [
                    $reservedByField => "",
                    $reservedAtField => ""
                ]]
            );
            return $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
        } catch (Exception $e) {
            error_log('Room reservation cancellation error: ' . $e->getMessage());
            throw $e;
        }
    }
}