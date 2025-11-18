<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../utils/scoreApi.php';
require_once __DIR__ . '/../utils/LocalFileHelper.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class RentalService
{
    private $rentalCollection;
    private $unitCollection;
    private $userCollection;
    private $ScoreApi;
    private $localFileHelper;

    public function __construct()
    {
        $db = Database::getDb();
        $this->rentalCollection = $db->Rental;
        $this->unitCollection = $db->Unit;
        $this->userCollection = $db->User;
        $this->ScoreApi = new ScoreApi();
        $this->localFileHelper = new LocalFileHelper();
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

    // public function createRentalService(array $rentalDetails, array $signatureImage) {
    //     try {
    //         // Validate unit and user exist
    //         $unit = $this->unitCollection->findOne(['_id' => new ObjectId($rentalDetails['unit'])]);
    //         $user = $this->userCollection->findOne(['_id' => new ObjectId($rentalDetails['user'])]);

    //         if (!$unit) {
    //             throw new Exception('Unit not found');
    //         }
    //         if (!$user) {
    //             throw new Exception('User not found');
    //         }

    //         // NEW RESERVATION CHECK (add just these 4 lines)
    //         if (isset($unit['reservedBy']) && (string)$unit['reservedBy'] !== $rentalDetails['user']) {
    //             throw new Exception('Unit is reserved by another user');
    //         }
    //         if ($unit['currentOccupants'] >= $unit['unitOccupants']) {
    //             throw new Exception('Unit is already at full capacity');
    //         }

    //         // Find rentals associated with this user 
    //         $existingRentals = $this->rentalCollection->find([
    //             'user' => new ObjectId($rentalDetails['user']),
    //             'status' => ['$in' => ['Pending', 'Active']]
    //         ]);

    //         if (iterator_count($existingRentals) > 0) {
    //             throw new Exception('User already has an active or pending rental');
    //         }

    //         // Handle access key and gender assignment
    //         $accessKey = null;
    //         $accessKeyIsTrue = isset($rentalDetails['accessKeyIsTrue']) 
    //             ? filter_var($rentalDetails['accessKeyIsTrue'], FILTER_VALIDATE_BOOLEAN)
    //             : false;

    //         if ($accessKeyIsTrue) {
    //             // if ($unit['accessKey']['isShared'] ?? false) {
    //             //     if ($rentalDetails['accessKey'] !== $unit['accessKey']['assignedKey']) {
    //             //         throw new Exception('Invalid access key');
    //             //     }
    //             //     $accessKey = $unit['accessKey']['assignedKey'];
    //             if ($unit['accessKey']['isShared'] ?? false) {
    //                 if ($rentalDetails['accessKey'] !== $unit['accessKey']['assignedKey']) {
    //                     throw new Exception('Invalid access key');
    //                 }
    //                 $now = new UTCDateTime();
    //                 if (!isset($unit['accessKey']['expiresAt']) || $now > $unit['accessKey']['expiresAt']) {
    //                     throw new Exception('Access key has expired');
    //                 }
    //                 $accessKey = $unit['accessKey']['assignedKey'];
    //             } else {
    //                 $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    //                 $accessKey = '';
    //                 for ($i = 0; $i < 6; $i++) {
    //                     $accessKey .= $characters[random_int(0, strlen($characters) - 1)];
    //                 }
    //                 $now = new UTCDateTime();
    //                 $expiresInHours = 12;
    //                 $expiresAt = new UTCDateTime($now->toDateTime()->modify("+{$expiresInHours} hours")->getTimestamp() * 1000);

    //                 // $now = new UTCDateTime();
    //                 // $expiresAt = new UTCDateTime($now->toDateTime()->modify("+1 minutes")->getTimestamp() * 1000);

    //                 $this->unitCollection->updateOne(
    //                     ['_id' => $unit['_id']],
    //                     ['$set' => [
    //                         'accessKey.isShared' => true,
    //                         'accessKey.assignedKey' => $accessKey,
    //                         'accessKey.createdAt' => $now,
    //                         'accessKey.expiresAt' => $expiresAt
    //                     ]]
    //                 );

    //             }
    //         } else {

    //             // -----------------------------------------------------------------------------------------------------------------------------------------
    //             // Add condition for room units not to include gender assignment

    //             if (!($unit['accessKey']['isShared'] ?? false)) {
    //                 if (empty($unit['genderAssignment'])) {
    //                     $this->unitCollection->updateOne(
    //                         ['_id' => $unit['_id']],
    //                         ['$set' => ['genderAssignment' => $user['gender']]]
    //                     );
    //                 } elseif ($unit['genderAssignment'] !== $user['gender']) {
    //                     throw new Exception("This unit is only available for {$unit['genderAssignment']}s.");
    //                 }
    //             }
    //         }
    //         $unit['unitPrice'] = (float)$unit['unitPrice'];
    //         $unit['_id'] = new ObjectId($unit['_id']);
    //         $unit['unitType'] = (string)$unit['unitType'];
    //         $user['_id'] = new ObjectId($user['_id']);

    //         // Handle parking (optional)
    //         $rawParking = $rentalDetails['parking'] ?? [];

    //         $parking = is_array($rawParking)
    //             ? $rawParking
    //             : ['hasParking' => false, 'fee' => 0.0];

    //         if (!isset($parking['fee'])) {
    //             $parking['fee'] = 50.0;
    //         }

    //         // handle subunits
    //         // --------------------------------------------------------------------------------------
    //         $selectedSubUnit = $rentalDetails['selectedSubUnit'] ?? null;

    //         if (!$selectedSubUnit || !isset($selectedSubUnit['price']) || !isset($selectedSubUnit['type'])) {
    //             throw new Exception('Selected sub-unit data is missing or invalid');
    //         }
    //         $selectedSubUnitData = [
    //             'type' => $selectedSubUnit['type'],
    //             'roomType' => $selectedSubUnit['roomType'] ?? null,
    //             'bedType' => $selectedSubUnit['bedType'] ?? null,
    //             'price' => (float)$selectedSubUnit['price'] ?? 0.0,
    //             'isAvailable' => $selectedSubUnit['isAvailable'] ?? true
    //         ];
    //         $totalRentalPrice = $selectedSubUnitData['price'] + ($parking['hasParking'] ? $parking['fee'] : 0.0);


    //         // IMAGE SIGN UPLOAD
    //         $signatureFiles = is_array($signatureImage) ? $signatureImage : [$signatureImage];

    //         $signatureUploads = array_map(function($file) {
    //             if ($file->getError() !== UPLOAD_ERR_OK) {
    //                 throw new Exception('Invalid signature image upload');
    //             }

    //             $uploaded = $this->localFileHelper->uploadImage($file);
    //             return [
    //                 'imageUrl' => (string) $uploaded['imageUrl'],
    //                 'fileId' => (string) $uploaded['fileId'],
    //                 '_id' => new ObjectId()
    //             ];
    //         }, $signatureFiles);

    //         $signatureData = $signatureUploads[0]; 

    //         $rental = new Rental(
    //             rentalStartDate: $rentalDetails['rentalStartDate'] ?? null,
    //             rentalEndDate: $rentalDetails['rentalEndDate'] ?? null,
    //             rentalPrice: $totalRentalPrice,
    //             unit: $unit['_id'],
    //             unitType: $selectedSubUnitData['roomType'] || $selectedSubUnitData['bedType'] || $unit['unitType'],
    //             user: $user['_id'],
    //             accessKey: $accessKey ?? $unit['accessKey']['assignedKey'] ?? null,
    //             parking: $parking,
    //             signature: $signatureData,
    //             selectedSubUnits:  $selectedSubUnitData
    //         );

    //         $result = $this->rentalCollection->insertOne($rental->toArray());
    //         $newRental = $this->rentalCollection->findOne(['_id' => $result->getInsertedId()]);

    //         // Update user and unit
    //         $this->userCollection->updateOne(
    //             ['_id' => $user['_id']],
    //             ['$push' => ['rentals' => $newRental['_id']]]
    //         );

    //         $this->unitCollection->updateOne(
    //             ['_id' => $unit['_id']],
    //             ['$push' => ['rentedHistory' => $newRental['_id']]]
    //         );

    //         // Increment unit occupants
    //         $this->unitCollection->updateOne(
    //             ['_id' => $unit['_id']],
    //             ['$inc' => ['currentOccupants' => 1]]
    //         );

    //         // Match either roomType or bedType - mark false
    //         $filter = [
    //             '_id' => $unit['_id']
    //         ];
    //         if ($selectedSubUnitData['roomType']) {
    //             $filter['subUnits.roomType'] = $selectedSubUnitData['roomType'];
    //             $updateKey = 'subUnits.$.isAvailable';
    //         } else {
    //             $filter['subUnits.bedType'] = $selectedSubUnitData['bedType'];
    //             $updateKey = 'subUnits.$.isAvailable';
    //         }
    //         $this->unitCollection->updateOne(
    //             $filter,
    //             ['$set' => [$updateKey => false]]
    //         );

    //         if (isset($unit['reservedBy'])) {
    //             $this->unitCollection->updateOne(
    //                 ['_id' => $unit['_id']],
    //                 ['$unset' => ['reservedBy' => '', 'reservedAt' => '']]
    //             );
    //         }

    //         return [
    //             'rental' => $newRental,
    //             'accessKey' => $accessKey
    //         ];   
    //     } catch (Exception $e) {
    //         error_log('RentalService error: ' . $e->getMessage());
    //         throw $e;
    //     }
    // }


    public function createRentalService(array $rentalDetails, array $signatureImage, ?array $guardianSignatureImage = null)
    {
        try {
            // -------------------------------
            // Fetch unit and user
            // -------------------------------
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($rentalDetails['unit'])]);
            $user = $this->userCollection->findOne(['_id' => new ObjectId($rentalDetails['user'])]);

            if (!$unit)
                throw new Exception('Unit not found');
            if (!$user)
                throw new Exception('User not found');

            // -------------------------------
            // Reservation and occupancy check
            // -------------------------------
            if (isset($unit['reservedBy']) && (string) $unit['reservedBy'] !== $rentalDetails['user']) {
                throw new Exception('Unit is reserved by another user');
            }
            if ($unit['currentOccupants'] >= $unit['unitOccupants']) {
                throw new Exception('Unit is already at full capacity');
            }

            // -------------------------------
            // Check existing rentals
            // -------------------------------
            $existingRentals = $this->rentalCollection->find([
                'user' => new ObjectId($rentalDetails['user']),
                'status' => ['$in' => ['Pending', 'Active']]
            ]);
            if (iterator_count($existingRentals) > 0) {
                throw new Exception('User already has an active or pending rental');
            }

            // -------------------------------
            // Access key / Gender Assignments
            // -------------------------------
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
                    // Generate new key
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
            } else {
                // Gender assignment for non-shared units
                $excludedUnits = [
                    '1-01',
                    '1-02',
                    '1-03',
                    '1-08',
                    '2-01',
                    '2-02',
                    '2-03',
                    '2-08',
                    '3-01',
                    '3-02',
                    '3-03',
                    '3-08'
                ];

                $unitIdentifier = $unit['unitNumber'] ?? null;

                $shouldAssignGender = !in_array($unitIdentifier, $excludedUnits);

                if ($shouldAssignGender && !($unit['accessKey']['isShared'] ?? false)) {
                    if (empty($unit['genderAssignment'])) {
                        $this->unitCollection->updateOne(
                            ['_id' => $unit['_id']],
                            ['$set' => ['genderAssignment' => $user['gender']]]
                        );
                    } elseif ($unit['genderAssignment'] !== $user['gender']) {
                        throw new Exception("This unit is only available for {$unit['genderAssignment']}s.");
                    }
                }
            }

            // -------------------------------
            // Handle parking
            // -------------------------------
            $rawParking = $rentalDetails['parking'] ?? null;

            // Decode if it's a JSON string
            if (is_string($rawParking)) {
                $decoded = json_decode($rawParking, true);
                $parking = is_array($decoded) ? $decoded : ['hasParking' => false, 'fee' => 0.0];
            } elseif (is_array($rawParking)) {
                $parking = $rawParking;
            } else {
                $parking = ['hasParking' => false, 'fee' => 0.0];
            }

            // Ensure fee is set
            if (!isset($parking['fee']) || !is_numeric($parking['fee'])) {
                $parking['fee'] = 50.0;
            }


            // -------------------------------
            // Handle selected sub-unit
            // -------------------------------
            // $selectedSubUnit = $rentalDetails['selectedSubUnit'] ?? null;
            // if (is_string($selectedSubUnit)) {
            //     $selectedSubUnit = json_decode($selectedSubUnit, true);
            // }

            // if (!$selectedSubUnit || !isset($selectedSubUnit['price']) || !isset($selectedSubUnit['type'])) {
            //     throw new Exception('Selected sub-unit data is missing or invalid');
            // }


            // $subUnitData = [
            //     'type' => $selectedSubUnit['type'],
            //     'roomType' => $selectedSubUnit['roomType'] ?? null,
            //     'bedType' => $selectedSubUnit['bedType'] ?? null,
            //     'price' => (float)($selectedSubUnit['price'] ?? 0.0),
            //     'isAvailable' => $selectedSubUnit['isAvailable'] ?? true
            // ];

            // Initialize default values
            $priceEntries = [];
            $priceName = 'default';
            $priceValue = 0.0;
            $selectedSubUnit = $rentalDetails['selectedSubUnit'] ?? null;
            // Decode JSON string into associative array if needed
            if (is_string($selectedSubUnit)) {
                $selectedSubUnit = json_decode($selectedSubUnit, true);
            }
            if (isset($selectedSubUnit['price'])) {
                if (is_array($selectedSubUnit['price'])) {
                    // If the price is an array, check if it contains named price objects
                    if (!empty($selectedSubUnit['price']) && is_array($selectedSubUnit['price'][0]) && array_key_exists('price', $selectedSubUnit['price'])) {
                        // Get the first price object
                        $priceEntries = $selectedSubUnit['price'];
                        $priceName = $priceEntries['name'] ?? 'default';
                        $priceValue = floatval($priceEntries['price'] ?? 0);
                    }
                    // Check if price is a single associative array with 'name' and 'price'
                    elseif (array_key_exists('name', $selectedSubUnit['price']) && array_key_exists('price', $selectedSubUnit['price'])) {
                        $priceName = $selectedSubUnit['price']['name'] ?? 'default';
                        $priceValue = floatval($selectedSubUnit['price']['price'] ?? 0);
                    } else {
                        // Price is an array of floats
                        $priceValue = floatval($selectedSubUnit['price'][0] ?? 0);
                    }
                } else {
                    // Price is a single float
                    $priceValue = floatval($selectedSubUnit['price']);
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

            $totalRentalPrice = $subUnitData['price']['price'] + ($parking['hasParking'] ? $parking['fee'] : 0.0);
            // // -------------------------------
            // // Handle signature upload
            // // -------------------------------
            // $signatureFiles = is_array($signatureImage) ? $signatureImage : [$signatureImage];
            // $signatureUploads = array_map(function($file) {
            //     if ($file->getError() !== UPLOAD_ERR_OK) throw new Exception('Invalid signature image upload');
            //     $uploaded = $this->localFileHelper->uploadImage($file);
            //     return [
            //         'imageUrl' => (string) $uploaded['imageUrl'],
            //         'fileId' => (string) $uploaded['fileId'],
            //         '_id' => new ObjectId()
            //     ];
            // }, $signatureFiles);
            // $signatureData = $signatureUploads[0];


            // // -------------------------------
            // // Handle guardian signature upload
            // // -------------------------------
            // $requiresGuardian = isset($user['age']) && $user['age'] < 21;
            // if ($requiresGuardian && empty($guardianSignatureImage)) {
            //     throw new Exception('Guardian signature is required for users under 21');
            // }
            // $guardianSignatureData = null;
            // if ($guardianSignatureImage) {
            //     $guardianFiles = is_array($guardianSignatureImage) ? $guardianSignatureImage : [$guardianSignatureImage];
            //     $guardianUploads = array_map(function($file) {
            //         if ($file->getError() !== UPLOAD_ERR_OK) throw new Exception('Invalid guardian signature image upload');
            //         $uploaded = $this->localFileHelper->uploadImage($file);
            //         return [
            //             'imageUrl' => (string) $uploaded['imageUrl'],
            //             'fileId' => (string) $uploaded['fileId'],
            //             '_id' => new ObjectId()
            //         ];
            //     }, $guardianFiles);
            //     $guardianSignatureData = $guardianUploads[0];
            // }

            // -------------------------------
            // Handle user signature upload (optional)
            // -------------------------------
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

            // -------------------------------
            // Handle guardian signature upload (optional)
            // -------------------------------
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

            // -------------------------------
            // Create Rental
            // -------------------------------
            $unitTypeForRental = $subUnitData['roomType'] ?? $subUnitData['bedType'] ?? $unit['unitType'];
            $rental = new Rental(
                rentalStartDate: $rentalDetails['rentalStartDate'] ?? null,
                rentalEndDate: $rentalDetails['rentalEndDate'] ?? null,
                rentalPrice: $totalRentalPrice,
                unit: $unit['_id'],
                unitType: $unitTypeForRental,
                user: $user['_id'],
                accessKey: $accessKey ?? $unit['accessKey']['assignedKey'] ?? null,
                parking: $parking,
                signature: $signatureData,
                guardianSignature: $guardianSignatureData,
                selectedSubUnits: $subUnitData
            );

            $result = $this->rentalCollection->insertOne($rental->toArray());
            $newRental = $this->rentalCollection->findOne(['_id' => $result->getInsertedId()]);

            // -------------------------------
            // Update unit and user
            // -------------------------------
            $this->userCollection->updateOne(
                ['_id' => $user['_id']],
                ['$push' => ['rentals' => $newRental['_id']]]
            );

            $this->unitCollection->updateOne(
                ['_id' => $unit['_id']],
                [
                    '$push' => ['rentedHistory' => $newRental['_id']],
                    '$inc' => ['currentOccupants' => 1]
                ]
            );

            // Mark sub-unit unavailable
            $filter = ['_id' => $unit['_id']];
            if ($subUnitData['roomType']) {
                $filter['subUnits.roomType'] = $subUnitData['roomType'];
            } else {
                $filter['subUnits.bedType'] = $subUnitData['bedType'];
            }
            $this->unitCollection->updateOne($filter, ['$set' => ['subUnits.$.isAvailable' => false]]);

            // Clear reservedBy if exists
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


    public function deleteRentalService(string $rentalId): bool
    {
        try {
            $rental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rental) {
                throw new Exception('Rental not found');
            }
            // if ($rental['status'] !== 'Pending' && $rental['status'] !== 'Rejected') {
            //     throw new Exception('Cannot delete an approved rental');
            // }

            if (isset($rental['signature']['fileId'])) {
                try {
                    $this->localFileHelper->deleteImage($rental['signature']['fileId']);
                    // Or use $this->ImageKitService->deleteImage(...) if switching back
                } catch (Exception $e) {
                    error_log("Failed to delete signature image {$rental['signature']['fileId']}: " . $e->getMessage());
                }
            }

            // Re-mark sub-unit as available
            $subUnit = $rental['selectedSubUnits'] ?? null;
            if ($subUnit) {
                $filter = ['_id' => new ObjectId($rental['unit'])];
                if (isset($subUnit['roomType'])) {
                    $filter['subUnits.roomType'] = $subUnit['roomType'];
                } elseif (isset($subUnit['bedType'])) {
                    $filter['subUnits.bedType'] = $subUnit['bedType'];
                }
                $this->unitCollection->updateOne(
                    $filter,
                    ['$set' => ['subUnits.$.isAvailable' => true]]
                );
            }

            // remove rental from USER's rentals
            $this->userCollection->updateOne(
                ['_id' => new ObjectId($rental['user'])],
                ['$pull' => ['rentals' => new ObjectId($rentalId)]]
            );

            // Remove rental from UNIT'S rentedHistory and decrement current occupants
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])]);
            if ($unit) {
                $this->unitCollection->updateOne(
                    ['_id' => new ObjectId($rental['unit'])],
                    [
                        '$inc' => ['currentOccupants' => -1],
                        '$pull' => ['rentedHistory' => new ObjectId($rentalId)]
                    ]
                );
            }
            // Delete the rental
            $this->rentalCollection->deleteOne(['_id' => new ObjectId($rentalId)]);

            return true;
        } catch (Exception $e) {
            error_log('DeleteRentalService error: ' . $e->getMessage());
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
                    // payerData
                    'unit' => (string) $doc['unit'] ?? null,
                    'unitType' => $doc['unitType'] ?? null,
                    'user' => (string) $doc['user'] ?? null,
                    'accessKey' => $doc['accessKey'] ?? null,
                    'parking' => [
                        'hasParking' => $doc['parking']['hasParking'] ?? false,
                        'fee' => $doc['parking']['fee'] ?? 0.0,
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

                // $rental['selectedSubUnits'] = [
                //     'type' => $doc['selectedSubUnits']['type'] ?? null,
                //     'roomType' => $doc['selectedSubUnits']['roomType'] ?? null,
                //     'bedType' => $doc['selectedSubUnits']['bedType'] ?? null,
                //     'price' => $doc['selectedSubUnits']['price'] ?? 0.0,
                //     'isAvailable' => $doc['selectedSubUnits']['isAvailable'] ?? true,
                // ];
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
                return []; // Return empty array if no rentals exist
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
                    // payerData
                    'unit' => (string) $doc['unit'] ?? null,
                    'unitType' => $doc['unitType'] ?? null,
                    'user' => (string) $doc['user'] ?? null,
                    'accessKey' => $doc['accessKey'] ?? null,
                    'parking' => [
                        'hasParking' => $doc['parking']['hasParking'] ?? false,
                        'fee' => $doc['parking']['fee'] ?? 0.0,
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

                // $rental['selectedSubUnits'] = [
                //     'type' => $doc['selectedSubUnits']['type'] ?? null,
                //     'roomType' => $doc['selectedSubUnits']['roomType'] ?? null,
                //     'bedType' => $doc['selectedSubUnits']['bedType'] ?? null,
                //     'price' => $doc['selectedSubUnits']['price'] ?? 0.0,
                //     'isAvailable' => $doc['selectedSubUnits']['isAvailable'] ?? true,
                // ];
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

    public function updateRentalService($rentalId, $rentalDetails)
    {
        try {
            $rentalToUpdate = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rentalToUpdate) {
                throw new Exception('Rental not found');
            }

            $this->rentalCollection->updateOne(
                ['_id' => new ObjectId($rentalId)],
                ['$set' => $rentalDetails]
            );

            $updatedRental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            return $updatedRental;
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

            // Update rental status to 'Ended'
            $this->rentalCollection->updateOne(
                ['_id' => new ObjectId($rentalId)],
                ['$set' => ['status' => 'Ended']]
            );

            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }

            // Decrement current occupants
            $this->unitCollection->updateOne(
                ['_id' => new ObjectId($rental['unit'])],
                ['$inc' => ['currentOccupants' => -1]]
            );

            $subUnit = $rental['selectedSubUnits'] ?? null;
            if ($subUnit) {
                $filter = ['_id' => new ObjectId($rental['unit'])];
                if (isset($subUnit['roomType'])) {
                    $filter['subUnits.roomType'] = $subUnit['roomType'];
                } elseif (isset($subUnit['bedType'])) {
                    $filter['subUnits.bedType'] = $subUnit['bedType'];
                }

                $this->unitCollection->updateOne(
                    $filter,
                    ['$set' => ['subUnits.$.isAvailable' => true]]
                );
            }


            // Return the updated rental
            $updatedRental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            return $updatedRental;

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

            // Score the payer data
            $score = $this->ScoreApi->scorePayerData($rentalData['payerData'] ?? []) ?? 0; // Default to 0
            $isEligible = $score >= 60;

            // Ensure payerData exists
            $payerData = $rental['payerData'] ?? [];

            // Store validation result
            $payerData['isValidated'] = $isEligible;
            $payerData['score'] = $score;

            // Assign other payer data
            $payerData['firstName'] = $rentalData['payerData']['firstName'] ?? '';
            $payerData['lastName'] = $rentalData['payerData']['lastName'] ?? '';
            $payerData['email'] = $rentalData['payerData']['email'] ?? '';
            $payerData['idNumber'] = (string) ($rentalData['payerData']['idNumber'] ?? '');
            $payerData['salary'] = (float) $rentalData['payerData']['salary'] ?? 0;

            // Ensure bankName is a string
            $payerData['bankName'] = is_array($rentalData['payerData']['bankName'] ?? null) ?
                ($rentalData['payerData']['bankName']['value'] ?? '') :
                ($rentalData['payerData']['bankName'] ?? '');

            // Update the rental
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

    public function earlyEndRentalService($rentalId)
    {
        try {
            $rental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rental) {
                throw new Exception('Rental not found');
            }
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($rental['unit'])]);
            if (!$unit) {
                throw new Exception('Unit not found');
            }

            if ($rental['status'] !== 'Active') {
                throw new Exception('Cannot end a rental if it was not approved');
            }

            // Update rental status and set earlyEndDate
            $this->rentalCollection->updateOne(
                ['_id' => new ObjectId($rentalId)],
                [
                    '$set' => [
                        'status' => 'Ended',
                        'earlyEndDate' => new UTCDateTime() // Current timestamp
                    ]
                ]
            );

            // Decrement unit occupants
            $this->unitCollection->updateOne(
                ['_id' => new ObjectId($rental['unit'])],
                ['$inc' => ['currentOccupants' => -1]]
            );

            $subUnit = $rental['selectedSubUnits'] ?? null;
            if ($subUnit) {
                $filter = ['_id' => new ObjectId($rental['unit'])];
                if (isset($subUnit['roomType'])) {
                    $filter['subUnits.roomType'] = $subUnit['roomType'];
                } elseif (isset($subUnit['bedType'])) {
                    $filter['subUnits.bedType'] = $subUnit['bedType'];
                }

                $this->unitCollection->updateOne(
                    $filter,
                    ['$set' => ['subUnits.$.isAvailable' => true]]
                );
            }


            return $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);

        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

}