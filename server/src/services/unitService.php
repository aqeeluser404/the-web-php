<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
// require_once __DIR__ . '/../utils/imageKit.php';
require_once __DIR__ . '/../utils/LocalFileHelper.php';
require_once __DIR__ . '/../../src/services/rentalService.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class UnitService
{
    private $unitCollection;
    private $rentalCollection;
    private $userCollection;
    // private $ImageKitService;
    private $localFileHelper;
    private $rentalService;

    public function __construct()
    {
        $db = Database::getDb();
        $this->unitCollection = $db->Unit;
        $this->rentalCollection = $db->Rental;
        $this->userCollection = $db->User;
        // $this->ImageKitService = new ImageKitService();
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

    // protected function unitChecks($unit) {
    //     $updateData = [];

    //     // Status check
    //     $updateData['unitStatus'] = ($unit['currentOccupants'] >= $unit['unitOccupants']) 
    //         ? 'Occupied' 
    //         : 'Available';

    //     $now = new UTCDateTime();

    //     // 1. If unit is empty → clear everything
    //     if ($unit['currentOccupants'] == 0) {
    //         $updateData['accessKey.isShared'] = null;
    //         $updateData['accessKey.assignedKey'] = null;
    //         $updateData['accessKey.createdAt'] = null;
    //         $updateData['accessKey.expiresAt'] = null;
    //         $updateData['genderAssignment'] = null;
    //     }

    //     // 2. If access key is expired (but unit is not empty)
    //     else if (
    //         !empty($unit['accessKey']['expiresAt']) &&
    //         $unit['accessKey']['expiresAt'] <= $now
    //     ) {
    //         // Clear access key from the unit itself
    //         $updateData['accessKey.isShared'] = null;
    //         $updateData['accessKey.assignedKey'] = null;
    //         $updateData['accessKey.createdAt'] = null;
    //         $updateData['accessKey.expiresAt'] = null;

    //         // Find rentals linked to this unit with an access key assigned
    //         $rentalsWithKey = $this->rentalCollection->find([
    //             'unit' => new ObjectId($unit['_id']),
    //             'accessKey' => ['$exists' => true, '$ne' => null]
    //         ]);

    //         foreach ($rentalsWithKey as $rental) {
    //             // Clear access key from rental
    //             $this->rentalCollection->updateOne(
    //                 ['_id' => $rental['_id']],
    //                 ['$unset' => ['accessKey' => '']]
    //             );

    //             // Assign gender from the rental's user to the unit
    //             if (isset($rental['user'])) {
    //                 $user = $this->userCollection->findOne(['_id' => $rental['user']]);
    //                 if ($user && isset($user['gender'])) {
    //                     $updateData['genderAssignment'] = $user['gender'];
    //                 }
    //             }
    //         }
    //     }


    //     // 3. Gender validation (optional conflict check)
    //     if (!empty($unit['genderAssignment'])) {
    //         $existingUnit = $this->unitCollection->findOne([
    //             'unitNumber' => $unit['unitNumber'],
    //             '_id' => ['$ne' => new ObjectId($unit['_id'])]
    //         ]);

    //         if ($existingUnit && $existingUnit['genderAssignment'] !== $unit['genderAssignment']) {
    //             throw new Exception("Gender restriction: This unit is only available for " . $existingUnit['genderAssignment'] . "s.");
    //         }
    //     }

    //     // Apply update
    //     $this->unitCollection->updateOne(
    //         ['_id' => new ObjectId($unit['_id'])],
    //         ['$set' => $updateData]
    //     );
    // }

    protected function unitChecks($unit)
    {
        error_log("unitChecks called for unit: " . json_encode($unit));
        $updateData = [];

        // ========== SAFETY CHECK: Recalculate currentOccupants from subUnits ==========
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

                // Normalize to boolean
                $isAvailable = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                // If it's explicitly false (boolean false, "false", 0), count as occupied
                if ($isAvailable === false) {
                    $occupiedCount++;
                }
            }
        } else {
            $occupiedCount = $unit['currentOccupants'] ?? 0;
            error_log("WARNING: Unit {$unit['unitNumber']} has no subUnits array, keeping currentOccupants = {$occupiedCount}");
        }
        
        $updateData['currentOccupants'] = $occupiedCount;

        // Status check: compare recalculated currentOccupants vs max unitOccupants
        $capacity = $unit['unitOccupants'] ?? 0;
        $updateData['unitStatus'] = ($occupiedCount >= $capacity)
            ? 'Occupied'
            : 'Available';

        $now = new UTCDateTime();

        // 1. If unit is empty → clear everything
        if ($occupiedCount == 0) {
            $updateData['accessKey.isShared'] = null;
            $updateData['accessKey.assignedKey'] = null;
            $updateData['accessKey.createdAt'] = null;
            $updateData['accessKey.expiresAt'] = null;
            $updateData['genderAssignment'] = null;
        }

        // 2. If access key is expired (but unit is not empty)
        else if (
            isset($unit['accessKey']['expiresAt']) && 
            !empty($unit['accessKey']['expiresAt']) &&
            $unit['accessKey']['expiresAt'] <= $now
        ) {
            // Clear access key from the unit itself
            $updateData['accessKey.isShared'] = null;
            $updateData['accessKey.assignedKey'] = null;
            $updateData['accessKey.createdAt'] = null;
            $updateData['accessKey.expiresAt'] = null;

            // Find rentals linked to this unit with an access key assigned
            $rentalsWithKey = iterator_to_array($this->rentalCollection->find([
                'unit' => new ObjectId($unit['_id']),
                'accessKey' => ['$exists' => true, '$ne' => null]
            ]));

            $rentalCount = count($rentalsWithKey);

            if ($rentalCount > 1) {
                foreach ($rentalsWithKey as $rental) {
                    try {
                        $this->rentalService->deleteRentalService((string) $rental['_id']);
                    } catch (Exception $e) {
                        error_log("Failed to delete rental {$rental['_id']}: " . $e->getMessage());
                    }
                }
                $updateData['genderAssignment'] = null;
            } else if ($rentalCount === 1) {
                // One person → keep logic as is
                $rental = $rentalsWithKey[0];

                // Clear access key from rental
                $this->rentalCollection->updateOne(
                    ['_id' => $rental['_id']],
                    ['$unset' => ['accessKey' => '']]
                );

                // Assign gender from user
                if (isset($rental['user'])) {
                    $user = $this->userCollection->findOne(['_id' => $rental['user']]);
                    if ($user && isset($user['gender'])) {
                        $updateData['genderAssignment'] = $user['gender'];
                    }
                }
            }
        }

        // If unit has exactly one occupant and no gender assigned yet
        if ($occupiedCount === 1 && empty($unit['genderAssignment'])) {
            $rental = $this->rentalCollection->findOne([
                'unit' => new ObjectId($unit['_id']),
                'status' => 'Active' // or include Pending if you want to lock earlier
            ]);

            if ($rental && isset($rental['user'])) {
                $user = $this->userCollection->findOne(['_id' => $rental['user']]);
                if ($user && isset($user['gender'])) {
                    $updateData['genderAssignment'] = $user['gender'];
                }
            }
        }

        // 3. Gender validation (optional conflict check)
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

        if (!isset($unit['unitYear']) || $unit['unitYear'] === null) {
            $updateData['unitYear'] = 2026;
            error_log("Added unitYear = 2026 to unit: {$unit['unitNumber']}");
        }

        // Apply update - only include fields that are set
        if (!empty($updateData)) {
            $this->unitCollection->updateOne(
                ['_id' => new ObjectId($unit['_id'])],
                ['$set' => $updateData]
            );
        }

        // $this->fixRentalDatesTo2026();
    }

    protected function fixRentalDatesTo2026()
    {
        try {
            // Find rentals with 2027 dates
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

                    // Apply updates if any
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

    // protected function unitChecks($unit)
    // {
    //     $updateData = [];

    //     // Status check
    //     $updateData['unitStatus'] = ($unit['currentOccupants'] >= $unit['unitOccupants'])
    //         ? 'Occupied'
    //         : 'Available';

    //     $now = new UTCDateTime();

    //     // 1. If unit is empty → clear everything
    //     if ($unit['currentOccupants'] == 0) {
    //         $updateData['accessKey.isShared'] = null;
    //         $updateData['accessKey.assignedKey'] = null;
    //         $updateData['accessKey.createdAt'] = null;
    //         $updateData['accessKey.expiresAt'] = null;
    //         $updateData['genderAssignment'] = null;
    //     }

    //     // 2. If access key is expired (but unit is not empty)
    //     else if (
    //         !empty($unit['accessKey']['expiresAt']) &&
    //         $unit['accessKey']['expiresAt'] <= $now
    //     ) {
    //         // Clear access key from the unit itself
    //         $updateData['accessKey.isShared'] = null;
    //         $updateData['accessKey.assignedKey'] = null;
    //         $updateData['accessKey.createdAt'] = null;
    //         $updateData['accessKey.expiresAt'] = null;

    //         // Find rentals linked to this unit with an access key assigned
    //         $rentalsWithKey = iterator_to_array($this->rentalCollection->find([
    //             'unit' => new ObjectId($unit['_id']),
    //             'accessKey' => ['$exists' => true, '$ne' => null]
    //         ]));

    //         $rentalCount = count($rentalsWithKey);

    //         if ($rentalCount > 1) {
    //             foreach ($rentalsWithKey as $rental) {
    //                 try {
    //                     $this->rentalService->deleteRentalService((string) $rental['_id']);
    //                 } catch (Exception $e) {
    //                     error_log("Failed to delete rental {$rental['_id']}: " . $e->getMessage());
    //                 }
    //             }

    //             $updateData['genderAssignment'] = null;
    //         } else if ($rentalCount === 1) {
    //             // One person → keep logic as is
    //             $rental = $rentalsWithKey[0];

    //             // Clear access key from rental
    //             $this->rentalCollection->updateOne(
    //                 ['_id' => $rental['_id']],
    //                 ['$unset' => ['accessKey' => '']]
    //             );

    //             // Assign gender from user
    //             if (isset($rental['user'])) {
    //                 $user = $this->userCollection->findOne(['_id' => $rental['user']]);
    //                 if ($user && isset($user['gender'])) {
    //                     $updateData['genderAssignment'] = $user['gender'];
    //                 }
    //             }
    //         }
    //     }

    //     // 3. Gender validation (optional conflict check)
    //     if (!empty($updateData['genderAssignment'])) {
    //         $existingUnit = $this->unitCollection->findOne([
    //             'unitNumber' => $unit['unitNumber'],
    //             '_id' => ['$ne' => new ObjectId($unit['_id'])]
    //         ]);

    //         if ($existingUnit && $existingUnit['genderAssignment'] !== $updateData['genderAssignment']) {
    //             throw new Exception("Gender restriction: This unit is only available for " . $existingUnit['genderAssignment'] . "s.");
    //         }
    //     }

    //     // Apply update
    //     $this->unitCollection->updateOne(
    //         ['_id' => new ObjectId($unit['_id'])],
    //         ['$set' => $updateData]
    //     );
    // }

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

            // Check if requesting user is the one who reserved it
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
            // Retrieve the unit document by ID
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }

            // Ensure subUnits exists and the requested room index is valid
            if (empty($unit['subUnits']) || !isset($unit['subUnits'][$roomIndex])) {
                throw new Exception('Room not found in unit');
            }

            $room = $unit['subUnits'][$roomIndex];

            // Check if the room is already reserved
            if (isset($room['reservedBy'])) {
                throw new Exception('Room is already reserved');
            }

            // Build the update path for nested reservedBy and reservedAt
            $reservedByField = 'subUnits.' . $roomIndex . '.reservedBy';
            $reservedAtField = 'subUnits.' . $roomIndex . '.reservedAt';

            // Perform the update on the subUnit array element
            $this->unitCollection->updateOne(
                ['_id' => new ObjectId($unitId)],
                ['$set' => [
                    $reservedByField => new ObjectId($userId),
                    $reservedAtField => new UTCDateTime()
                ]]
            );

            // Return the updated unit document
            return $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
        } catch (Exception $e) {
            error_log('Room reservation error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function cancelReserveRoomService($unitId, $roomIndex, $requestingUserId)
    {
        try {
            // Retrieve the unit document by ID
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }

            // Ensure subUnits exists and the requested room index is valid
            if (empty($unit['subUnits']) || !isset($unit['subUnits'][$roomIndex])) {
                throw new Exception('Room not found in unit');
            }

            $room = $unit['subUnits'][$roomIndex];

            // Check if the room is reserved
            if (!isset($room['reservedBy'])) {
                throw new Exception('Room is not reserved');
            }

            // Check whether the requesting user is the one who reserved the room
            if ((string) $room['reservedBy'] !== $requestingUserId) {
                throw new Exception('Only the reserving user can cancel this reservation');
            }

            // Build the update fields to unset nested reservedBy and reservedAt
            $reservedByField = 'subUnits.' . $roomIndex . '.reservedBy';
            $reservedAtField = 'subUnits.' . $roomIndex . '.reservedAt';

            // Perform the update to unset the reservation fields for the room
            $this->unitCollection->updateOne(
                ['_id' => new ObjectId($unitId)],
                ['$unset' => [
                    $reservedByField => "",
                    $reservedAtField => ""
                ]]
            );

            // Return the updated unit document
            return $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
        } catch (Exception $e) {
            error_log('Room reservation cancellation error: ' . $e->getMessage());
            throw $e;
        }
    }

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

                // Upload the image
                // $uploaded = $this->ImageKitService->uploadImage($file);
                $uploaded = $this->localFileHelper->uploadImage($file);

                return [
                    'imageUrl' => (string) $uploaded['imageUrl'],
                    'fileId' => (string) $uploaded['fileId'],
                    '_id' => new ObjectId()
                ];
            }, $unitImages['images'] ?? []);

            // Process subUnits if provided
            // $subUnits = array_map(function($subUnit) {
            //     return [
            //         'type' => $subUnit['type'] ?? 'room', 
            //         'roomType' => $subUnit['roomType'] ?? null, 
            //         'bedType' => $subUnit['bedType'] ?? null,
            //         // 'price' => $subUnit['price'] ?? 0,

            //         'price' => is_array($subUnit['price'] ?? null)
            //             ? array_map('floatval', $subUnit['price'])
            //             : [floatval($subUnit['price'] ?? 0)],

            //         'isAvailable' => true
            //     ];
            // }, $unitDetails['subUnits'] ?? []);

            // Process subUnits if provided
            $subUnits = array_map(function ($subUnit) {
                // Normalize prices to array of ['name' => ..., 'price' => ...]
                $prices = [];
                if (isset($subUnit['price'])) {
                    if (is_array($subUnit['price'])) {
                        // If price is already array, check if it's array of floats or array of named prices
                        if (!empty($subUnit['price']) && is_array($subUnit['price']) && array_key_exists('price', $subUnit['price'][0])) {

                            // Assume array of named prices
                            foreach ($subUnit['price'] as $priceEntry) {
                                $prices[] = [
                                    'name' => $priceEntry['name'] ?? 'default',
                                    'price' => floatval($priceEntry['price'] ?? 0)
                                ];
                            }
                        } else {
                            // Assume array of floats (old style), convert to named
                            foreach ($subUnit['price'] as $priceVal) {
                                $prices[] = ['name' => 'default', 'price' => floatval($priceVal)];
                            }
                        }
                    } else {
                        // Single float price (old style)
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
            // $unitDetails['unitPrice'] = (float)$unitDetails['unitPrice'];

            // if (!isset($unitDetails['unitPrice']) || $unitDetails['unitPrice'] === '') {
            //     $allPrices = array_merge(...array_map(function ($subUnit) {
            //         return is_array($subUnit['price'] ?? null)
            //             ? $subUnit['price']
            //             : [(float) ($subUnit['price'] ?? 0)];
            //     }, $subUnits));

            //     $unitDetails['unitPrice'] = count($allPrices) > 0
            //         ? min(array_map('floatval', $allPrices))
            //         : 0;
            // } else {
            //     $unitDetails['unitPrice'] = (float) $unitDetails['unitPrice'];
            // }

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
                // __v: 0
            );

            // Insert into MongoDB
            $result = $this->unitCollection->insertOne($unit->toArray());

            // Return the inserted unit
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

            // RUN UNIT CHECK AND UPDATE
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

                // SubUnits (new)
                $unit['subUnits'] = [];

                // if (!empty($doc['subUnits'])) {
                //     // Convert BSONArray to PHP array
                //     $subUnitsArray = $doc['subUnits'] instanceof \MongoDB\Model\BSONArray
                //         ? $doc['subUnits']->getArrayCopy()
                //         : (array) $doc['subUnits'];

                //     foreach ($subUnitsArray as $sub) {
                //         $unit['subUnits'][] = [
                //             'type' => $sub['type'] ?? 'room',
                //             'roomType' => $sub['roomType'] ?? null,
                //             'bedType' => $sub['bedType'] ?? null,
                //             // 'price' => $sub['price'] ?? 0,

                //             'price' => (
                //                 $sub['price'] instanceof \MongoDB\Model\BSONArray
                //                 ? array_map('floatval', $sub['price']->getArrayCopy())
                //                 : (is_array($sub['price'] ?? null)
                //                     ? array_map('floatval', $sub['price'])
                //                     : [floatval($sub['price'] ?? 0)]
                //                 )
                //             ),

                //             'isAvailable' => $sub['isAvailable'] ?? true
                //         ];
                //     }
                // }


                // RUN UNIT CHECK AND UPDATE
                $doc = $this->unitCollection->findOne(['_id' => new ObjectId($unit['_id'])]);
                if ($doc) {
                    $this->unitChecks($doc);
                } else {
                    error_log("Unit not found for id {$unit['_id']}");
                }
                // $this->unitChecks($unit);

                if (!empty($doc['subUnits'])) {
                    // Convert BSONArray to PHP array
                    $subUnitsArray = $doc['subUnits'] instanceof \MongoDB\Model\BSONArray
                        ? $doc['subUnits']->getArrayCopy()
                        : (array) $doc['subUnits'];

                    foreach ($subUnitsArray as $sub) {
                        // Initialize prices array
                        $pricesArray = [];

                        if (isset($sub['price']) && is_array($sub['price'])) {
                            foreach ($sub['price'] as $priceEntry) {
                                // Convert BSONDocument to array if necessary
                                if ($priceEntry instanceof \MongoDB\Model\BSONDocument) {
                                    $priceEntry = $priceEntry->getArrayCopy();
                                }
                                $pricesArray[] = [
                                    'name' => $priceEntry['name'] ?? 'default',
                                    'price' => floatval($priceEntry['price'] ?? 0),
                                ];
                            }
                        } elseif (isset($sub['price'])) {
                            // Handle price as BSONArray, PHP array, or single value
                            if ($sub['price'] instanceof \MongoDB\Model\BSONArray) {
                                $pricesArray = array_map(function ($p) {
                                    // Convert BSONDocument to array if necessary
                                    if ($p instanceof \MongoDB\Model\BSONDocument) {
                                        $p = $p->getArrayCopy();
                                    }
                                    // If $p is array with price field
                                    if (is_array($p) && array_key_exists('price', $p)) {
                                        return [
                                            'name' => $p['name'] ?? 'default',
                                            'price' => floatval($p['price'] ?? 0)
                                        ];
                                    }
                                    // Else treat as float
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


    // public function updateUnitService($unitId, $unitDetails, $unitImages = [])
    // {
    //     try {
    //         if (!empty($data['subUnits'])) {
    //             $data['subUnits'] = json_decode($unitDetails['subUnits'], true);
    //         }
    //         $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
    //         if (!$unit)
    //             throw new Exception('Unit not found');

    //         // --- Handle images ---
    //         $imageData = [];
    //         if (!empty($unitImages['images'])) {
    //             foreach ($unit['images'] ?? [] as $image) {
    //                 $this->localFileHelper->deleteImage($image['fileId']);
    //             }
    //             $imageData = array_map(function ($file) {
    //                 return $this->localFileHelper->uploadImage($file);
    //             }, $unitImages['images']);
    //         } else {
    //             $imageData = $unit['images'] ?? [];
    //         }

    //         // --- Ensure subUnits exist for old units ---
    //         // $existingSubUnits = $unit['subUnits'] ?? [];
    //         // $newSubUnits = $unitDetails['subUnits'] ?? $existingSubUnits;

    //         // Ensure subUnits is an array
    //         $newSubUnits = [];
    //         if (!empty($unitDetails['subUnits'])) {
    //             $decoded = json_decode($unitDetails['subUnits'], true);
    //             if (is_array($decoded)) {
    //                 $newSubUnits = $decoded;
    //             }
    //         } else {
    //             $newSubUnits = $unit['subUnits'] ?? [];
    //         }

    //         // Normalize subUnits
    //         $normalizedSubUnits = array_map(function ($subUnit) {
    //             return [
    //                 'type' => $subUnit['type'] ?? 'room',
    //                 'roomType' => $subUnit['roomType'] ?? null,
    //                 'bedType' => $subUnit['bedType'] ?? null,
    //                 // 'price' => $subUnit['price'] ?? 0,

    //                 'price' => is_array($subUnit['price'] ?? null)
    //                     ? array_map('floatval', $subUnit['price'])
    //                     : [floatval($subUnit['price'] ?? 0)],

    //                 'isAvailable' => $subUnit['isAvailable'] ?? true
    //             ];
    //         }, $newSubUnits);

    //         // Automatically adjust occupancy
    //         $unitOccupants = count($normalizedSubUnits);

    //         if (!isset($unitDetails['unitPrice']) || $unitDetails['unitPrice'] === '') {
    //             $allPrices = array_merge(...array_map(function ($subUnit) {
    //                 return is_array($subUnit['price'] ?? null)
    //                     ? $subUnit['price']
    //                     : [(float) ($subUnit['price'] ?? 0)];
    //             }, $normalizedSubUnits));

    //             $unitDetails['unitPrice'] = count($allPrices) > 0
    //                 ? min(array_map('floatval', $allPrices))
    //                 : 0;
    //         } else {
    //             $unitDetails['unitPrice'] = (float) $unitDetails['unitPrice'];
    //         }

    //         $updateData = [
    //             'unitNumber' => (string) ($unitDetails['unitNumber'] ?? $unit['unitNumber']),
    //             'floorLevel' => (string) ($unitDetails['floorLevel'] ?? $unit['floorLevel']),
    //             'unitType' => (string) ($unitDetails['unitType'] ?? $unit['unitType']),
    //             'unitOccupants' => $unitOccupants,
    //             'unitDescription' => (string) ($unitDetails['unitDescription'] ?? $unit['unitDescription']),
    //             'unitPrice' => (float) ($unitDetails['unitPrice'] ?? $unit['unitPrice']),
    //             'genderAssignment' => $unitDetails['genderAssignment'] ?? $unit['genderAssignment'] ?? null,
    //             'images' => !empty($imageData) ? $imageData : $unit['images'],
    //             'subUnits' => $normalizedSubUnits
    //         ];

    //         $result = $this->unitCollection->updateOne(
    //             ['_id' => new ObjectId($unitId)],
    //             ['$set' => $updateData]
    //         );

    //         if ($result->getModifiedCount() === 0) {
    //             error_log("No documents were modified");
    //         }

    //     } catch (Exception $e) {
    //         error_log('Update failed: ' . $e->getMessage());
    //         throw $e;
    //     }
    // }


    public function updateUnitService($unitId, $unitDetails, $unitImages = [])
    {
        try {
            // Handle subUnits input (decode if string)
            if (!empty($unitDetails['subUnits']) && is_string($unitDetails['subUnits'])) {
                $decoded = json_decode($unitDetails['subUnits'], true);
                if (is_array($decoded)) {
                    $newSubUnits = $decoded;
                } else {
                    $newSubUnits = [];
                }
            } else {
                $newSubUnits = $unitDetails['subUnits'] ?? [];
            }

            // Normalize subUnits with named prices, backward compatible
            $normalizedSubUnits = array_map(function ($subUnit) {
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
                    'type' => $subUnit['type'] ?? 'room',
                    'roomType' => $subUnit['roomType'] ?? null,
                    'bedType' => $subUnit['bedType'] ?? null,
                    'price' => $prices,
                    'isAvailable' => $subUnit['isAvailable'] ?? true
                ];
            }, $newSubUnits);


            // Calculate occupants from normalized subUnits
            $unitOccupants = count($normalizedSubUnits);

            // Calculate unitPrice from all prices if not provided
            if (!isset($unitDetails['unitPrice']) || $unitDetails['unitPrice'] === '') {
                $allPrices = array_merge(...array_map(function ($subUnit) {
                    return array_map(fn($p) => floatval($p['price'] ?? 0), $subUnit['price']);
                }, $normalizedSubUnits));
                $unitDetails['unitPrice'] = count($allPrices) > 0 ? min($allPrices) : 0;
            } else {
                $unitDetails['unitPrice'] = (float) $unitDetails['unitPrice'];
            }

            // Get existing unit from DB
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }

            // Handle images removal/upload
            $imageData = [];
            if (!empty($unitImages['images'])) {
                // Delete old images
                foreach ($unit['images'] ?? [] as $image) {
                    $this->localFileHelper->deleteImage($image['fileId']);
                }
                // Upload new images
                $imageData = array_map(function ($file) {
                    return $this->localFileHelper->uploadImage($file);
                }, $unitImages['images']);
            } else {
                $imageData = $unit['images'] ?? [];
            }

            // Prepare update data
            $updateData = [
                'unitNumber' => (string) ($unitDetails['unitNumber'] ?? $unit['unitNumber']),
                'floorLevel' => (string) ($unitDetails['floorLevel'] ?? $unit['floorLevel']),
                'unitType' => (string) ($unitDetails['unitType'] ?? $unit['unitType']),
                'unitOccupants' => $unitOccupants,
                'unitDescription' => (string) ($unitDetails['unitDescription'] ?? $unit['unitDescription']),
                'unitPrice' => (float) ($unitDetails['unitPrice'] ?? $unit['unitPrice']),
                'unitYear' => isset($unitDetails['unitYear']) ? (int) $unitDetails['unitYear'] : ($unit['unitYear'] ?? null),
                'genderAssignment' => $unitDetails['genderAssignment'] ?? $unit['genderAssignment'] ?? null,
                'images' => !empty($imageData) ? $imageData : ($unit['images'] ?? []),
                'subUnits' => $normalizedSubUnits
            ];

            // Execute update
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
                    // $this->ImageKitService->deleteImage($image['fileId']);
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
}