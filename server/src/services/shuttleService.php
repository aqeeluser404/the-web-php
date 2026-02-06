<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;
use MongoDB\Operation\FindOneAndUpdate;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class shuttleService {
    private $shuttleCollection;
    private $userCollection;

    public function __construct() {
        $db = Database::getDb();
        $this->shuttleCollection = $db->Shuttle;
        $this->userCollection = $db->User;
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

    private function generateCustomId(): string {
        return (string) mt_rand(100000, 999999);
    }

public function createShuttleService(array $shuttleDetails): array {
    try {
        if (empty($shuttleDetails['user']) || empty($shuttleDetails['bookingTimeslot'])) {
            throw new Exception('Missing required fields: user and bookingTimeslot are required');
        }
        
        $attempts = 0;
        do {
            $shuttleNumber = $this->generateCustomId();
            $existingShuttle = $this->shuttleCollection->findOne(['shuttleNumber' => $shuttleNumber]);
            if ($existingShuttle && $attempts++ > 3) {
                error_log("Multiple collisions generating shuttleNumber");
            }
        } while ($existingShuttle !== null);

        // Convert the ISO string to UTCDateTime
        $bookingTime = strtotime($shuttleDetails['bookingTimeslot']);
        if ($bookingTime === false) {
            throw new Exception('Invalid bookingTimeslot format');
        }

        $shuttleData = [
            'shuttleNumber' => $shuttleNumber,
            'pickupLocation' => $shuttleDetails['pickupLocation'] ?? 'University',
            'dropoffLocation' => $shuttleDetails['dropoffLocation'] ?? 'Residence',
            'bookingTimeslot' => new MongoDB\BSON\UTCDateTime($bookingTime * 1000), // Convert to milliseconds
            'createdAt' => new MongoDB\BSON\UTCDateTime(),
            'status' => 'Pending',
            'user' => new MongoDB\BSON\ObjectId($shuttleDetails['user']),
        ];
        
        $insertResult = $this->shuttleCollection->insertOne($shuttleData);
        
        $this->userCollection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($shuttleDetails['user'])],
            ['$push' => ['shuttles' => $insertResult->getInsertedId()]]
        );
        
        return $shuttleData;
    } catch (Exception $error) {
        error_log('Shuttle creation failed: ' . $error->getMessage());
        throw $error;
    }
}

    public function findShuttleByIdService($id) {
        try {
            $shuttle = $this->shuttleCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$shuttle) {
                throw new Exception('Shuttle not found');
            }
            return $shuttle;
        } catch(Exception $error) {
            error_log('Error finding shuttle by ID: ' . $error->getMessage());
            throw $error;
        }
    }

    public function findAllMyShuttlesService($userId) {
        try {
            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);

            if (!$user) {
                throw new Exception('User not found');
            }
            if (empty($user['shuttles'])) {
                return [];
            }

            $shuttles = $this->shuttleCollection->find([
                '_id' => ['$in' => $user['shuttles']]
            ]);

            $results = [];
            foreach ($shuttles as $doc) {
                $shuttle = [
                    '_id' => (string)$doc['_id'],
                    'shuttleNumber' => $doc['shuttleNumber'] ?? null,
                    'pickupLocation' => $doc['pickupLocation'],
                    'dropoffLocation' => $doc['dropoffLocation'],
                    'bookingTimeslot' => $this->safeDateFormat($doc['bookingTimeslot'] ?? null),
                    'createdAt' => $this->safeDateFormat($doc['createdAt'] ?? null),
                    'status' => $doc['status'],
                    'user' => (string)$doc['user'],
                ];

                $shuttle['driverNotes'] = isset($doc['driverNotes']) ? [
                    'notes' => $doc['driverNotes']['notes'] ?? null,
                    'driverNotesDate' => $doc['driverNotes']['driverNotesDate'] ?? null,
                ] : null;

                $results[] = $shuttle;
            }
            
            return $results;
        } catch (Exception $error) {
            error_log('Error finding user shuttles: ' . $error->getMessage());
            throw $error;
        }
    }

    public function findAllShuttlesService() {
        try {
            $shuttles = $this->shuttleCollection->find();

            $results = [];
            foreach ($shuttles as $doc) {
                $shuttle = [
                    '_id' => (string)$doc['_id'],
                    'shuttleNumber' => $doc['shuttleNumber'] ?? null,
                    'pickupLocation' => $doc['pickupLocation'],
                    'dropoffLocation' => $doc['dropoffLocation'],
                    'bookingTimeslot' => $this->safeDateFormat($doc['bookingTimeslot'] ?? null),
                    'createdAt' => $this->safeDateFormat($doc['createdAt'] ?? null),
                    'status' => $doc['status'],
                    'user' => (string)$doc['user'],
                ];

                $shuttle['driverNotes'] = isset($doc['driverNotes']) ? [
                    'notes' => $doc['driverNotes']['notes'] ?? null,
                    'driverNotesDate' => $doc['driverNotes']['driverNotesDate'] ?? null,
                ] : null;

                $results[] = $shuttle;
            }
            
            return $results;
        } catch (Exception $error) {
            error_log('Error finding shuttles: ' . $error->getMessage());
            throw $error;
        }
    }

    public function updateShuttleService($shuttleId, $shuttleDetails) {
        try {
            $shuttleToUpdate = $this->shuttleCollection->findOneAndUpdate(
                ['_id' => new ObjectId($shuttleId)],
                ['$set' => $shuttleDetails],
                ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
            if (!$shuttleToUpdate) {
                throw new Exception('Shuttle not found');
            }
            return $shuttleToUpdate;
        } catch (Exception $error) {
            error_log('Error updating shuttle: ' . $error->getMessage());
            throw $error;
        }
    }

    public function deleteShuttleService($shuttleId) {
        try {
            $shuttle = $this->shuttleCollection->findOne(['_id' => new ObjectId($shuttleId)]);
            if (!$shuttle) {
                throw new Exception('Shuttle not found');
            }

            $this->userCollection->updateOne(
                ['_id' => new ObjectId($shuttle['user'])],
                ['$pull' => ['shuttles' => ['$in' => [new ObjectId($shuttleId), null]]]]
            );

            $this->shuttleCollection->deleteOne(['_id' => new ObjectId($shuttleId)]);

            return true;
        } catch (Exception $error) {
            error_log('Error deleting shuttle: ' . $error->getMessage());
            throw $error;
        }
    }
}
 