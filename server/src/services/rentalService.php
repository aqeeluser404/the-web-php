<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../utils/scoreApi.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class RentalService {
    private $rentalCollection;
    private $unitCollection;
    private $userCollection;
    private $ScoreApi;

    public function __construct() {
        $db = Database::getDb();
        $this->rentalCollection = $db->Rental;
        $this->unitCollection = $db->Unit;
        $this->userCollection = $db->User;
        $this->ScoreApi = new ScoreApi();
    }

    protected function safeDateFormat($dateValue) {
        if ($dateValue instanceof UTCDateTime) {
            return $dateValue->toDateTime()->format('Y-m-d\TH:i:s.vP');
        }
        if (is_string($dateValue)) {
            return $dateValue;
        }
        return null; 
    }

    public function createRentalService(array $rentalDetails): array {
        try {
            // Validate unit and user exist
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($rentalDetails['unit'])]);
            $user = $this->userCollection->findOne(['_id' => new ObjectId($rentalDetails['user'])]);
            
            if (!$unit) {
                throw new Exception('Unit not found');
            }
            if (!$user) {
                throw new Exception('User not found');
            }
            if ($unit['currentOccupants'] >= $unit['unitOccupants']) {
                throw new Exception('Unit is already at full capacity');
            }

            // Find rentals associated with this user 
            $existingRentals = $this->rentalCollection->find([
                'user' => new ObjectId($rentalDetails['user']),
                'status' => ['$in' => ['Pending', 'Active']]
            ]);
    
            if (iterator_count($existingRentals) > 0) {
                throw new Exception('User already has an active or pending rental');
            }
    
            // Handle access key and gender assignment
            $accessKey = null;
            $accessKeyIsTrue = $rentalDetails['accessKeyIsTrue'] ?? false;
    
            if ($accessKeyIsTrue) {
                if ($unit['accessKey']['isShared'] ?? false) {
                    if ($rentalDetails['accessKey'] !== $unit['accessKey']['assignedKey']) {
                        throw new Exception('Invalid access key');
                    }
                    $accessKey = $unit['accessKey']['assignedKey'];
                } else {
                    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
                    $accessKey = '';
                    for ($i = 0; $i < 6; $i++) {
                        $accessKey .= $characters[random_int(0, strlen($characters) - 1)];
                    }
                    $this->unitCollection->updateOne(
                        ['_id' => $unit['_id']],
                        ['$set' => [
                            'accessKey.isShared' => true,
                            'accessKey.assignedKey' => $accessKey
                        ]]
                    );
                }
            } else {
                if (!($unit['accessKey']['isShared'] ?? false)) {
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
            $unit['unitPrice'] = (float)$unit['unitPrice'];
            $unit['_id'] = new ObjectId($unit['_id']);
            $unit['unitType'] = (string)$unit['unitType'];
            $user['_id'] = new ObjectId($user['_id']);

            $rental = new Rental(
                //...
                // status: 'Pending',
                rentalStartDate: $rentalDetails['rentalStartDate'] ?? null,
                rentalEndDate: $rentalDetails['rentalEndDate'] ?? null,
                rentalPrice: $unit['unitPrice'],
                unit: $unit['_id'],
                unitType: $unit['unitType'],
                user: $user['_id'],
                accessKey: $accessKey ?? $unit['accessKey']['assignedKey'] ?? null,
            );
    
            $result = $this->rentalCollection->insertOne($rental->toArray());
            $newRental = $this->rentalCollection->findOne(['_id' => $result->getInsertedId()]);
    
            // Update user and unit
            $this->userCollection->updateOne(
                ['_id' => $user['_id']],
                ['$push' => ['rentals' => $newRental['_id']]]
            );
    
            $this->unitCollection->updateOne(
                ['_id' => $unit['_id']],
                ['$push' => ['rentedHistory' => $newRental['_id']]]
            );

            // Increment unit occupants
            $this->unitCollection->updateOne(
                ['_id' => $unit['_id']],
                ['$inc' => ['currentOccupants' => 1]]
            );
    
            return [
                'rental' => $newRental,
                'accessKey' => $accessKey
            ];   
        } catch (Exception $e) {
            error_log('RentalService error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteRentalService(string $rentalId): bool {
        try {
            $rental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rental) {
                throw new Exception('Rental not found');
            }
            // if ($rental['status'] !== 'Pending' && $rental['status'] !== 'Rejected') {
            //     throw new Exception('Cannot delete an approved rental');
            // }

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

    public function findRentalByIdService($rentalId) {
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

    public function findAllRentalsService(): array {
        try {
            $rentals = $this->rentalCollection->find();
            $results = [];

            foreach ($rentals as $doc) {
                $rental = [
                    '_id' => (string)$doc['_id'],
                    'applicationDate' => $this->safeDateFormat($doc['applicationDate'] ?? null),
                    'status' => $doc['status'] ?? null,
                    'rentalStartDate' => $this->safeDateFormat($doc['rentalStartDate'] ?? null),
                    'rentalEndDate' => $this->safeDateFormat($doc['rentalEndDate'] ?? null),
                    'earlyEndDate' => $this->safeDateFormat($doc['earlyEndDate'] ?? null),
                    'rentalPrice' => $doc['rentalPrice'] ?? null,
                    // payerData
                    'unit' => (string)$doc['unit'] ?? null,
                    'unitType' => $doc['unitType'] ?? null,
                    'user' => (string)$doc['user'] ?? null,
                    'accessKey' => $doc['accessKey'] ?? null
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
                $results[] = $rental;
            }
            return $results;
        } catch (Exception $e) {
            error_log('FindAllRentalsService error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function findAllMyRentalsService($userId) {
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
                    '_id' => (string)$doc['_id'],
                    'applicationDate' => $this->safeDateFormat($doc['applicationDate'] ?? null),
                    'status' => $doc['status'] ?? null,
                    'rentalStartDate' => $this->safeDateFormat($doc['rentalStartDate'] ?? null),
                    'rentalEndDate' => $this->safeDateFormat($doc['rentalEndDate'] ?? null),
                    'earlyEndDate' => $this->safeDateFormat($doc['earlyEndDate'] ?? null),
                    'rentalPrice' => $doc['rentalPrice'] ?? null,
                    // payerData
                    'unit' => (string)$doc['unit'] ?? null,
                    'unitType' => $doc['unitType'] ?? null,
                    'user' => (string)$doc['user'] ?? null,
                    'accessKey' => $doc['accessKey'] ?? null
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
                $results[] = $rental;
            }
            return $results;
        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateRentalService($rentalId, $rentalDetails) {
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

    public function endRentalService($rentalId) {
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

            // Return the updated rental
            $updatedRental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            return $updatedRental;

        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function verifyAndSavePayerService(string $rentalId, array $rentalData): array {
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
            $payerData['idNumber'] = (string)($rentalData['payerData']['idNumber'] ?? '');
            $payerData['salary'] = (float)$rentalData['payerData']['salary'] ?? 0;

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

    public function earlyEndRentalService($rentalId) {
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

            return $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);

        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

}