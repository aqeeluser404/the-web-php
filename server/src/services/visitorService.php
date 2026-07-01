<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;
use MongoDB\Operation\FindOneAndUpdate;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class visitorService {
    private $visitorCollection;
    private $userCollection;

    public function __construct() {
        $db = Database::getDb();
        $this->visitorCollection = $db->Visitor;
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

    public function createVisitorService(array $visitorDetails): array {
        try {
            if (empty($visitorDetails['user']) || empty($visitorDetails['bookingTimeslot'])) {
                throw new Exception('Missing required fields: user and bookingTimeslot are required');
            }
            
            $attempts = 0;
            do {
                $visitorNumber = $this->generateCustomId();
                $existingVisitor = $this->visitorCollection->findOne(['visitorNumber' => $visitorNumber]);
                if ($existingVisitor && $attempts++ > 3) {
                    error_log("Multiple collisions generating visitorNumber");
                }
            } while ($existingVisitor !== null);

            // Convert the ISO string to UTCDateTime
            $bookingTime = strtotime($visitorDetails['bookingTimeslot']);
            if ($bookingTime === false) {
                throw new Exception('Invalid bookingTimeslot format');
            }

            $visitorData = [
                'visitorNumber' => $visitorNumber,
                'firstName' => $visitorDetails['firstName'],
                'lastName' => $visitorDetails['lastName'],
                'bookingTimeslot' => new MongoDB\BSON\UTCDateTime($bookingTime * 1000), 
                'entryTimeslot' => new MongoDB\BSON\UTCDateTime($bookingTime * 1000),
                'exitTimeslot' => null,
                'status' => 'Pending',
                'user' => new MongoDB\BSON\ObjectId($visitorDetails['user']),
            ];
            
            $insertResult = $this->visitorCollection->insertOne($visitorData);
            
            $this->userCollection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($visitorData['user'])],
                ['$push' => ['visitors' => $insertResult->getInsertedId()]]
            );
            
            return $visitorData;
        } catch (Exception $error) {
            error_log('Visitor creation failed: ' . $error->getMessage());
            throw $error;
        }
    }

    public function findVisitorByIdService($id) {
        try {
            $visitor = $this->visitorCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$visitor) {
                throw new Exception('Visitor not found');
            }
            return $visitor;
        } catch(Exception $error) {
            error_log('Error finding visitor by ID: ' . $error->getMessage());
            throw $error;
        }
    }

    public function findAllMyVisitorsService($userId) {
        try {
            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);

            if (!$user) {
                throw new Exception('User not found');
            }
            if (empty($user['visitors'])) {
                return [];
            }

            $visitors = $this->visitorCollection->find([
                '_id' => ['$in' => $user['visitors']]
            ]);

            $results = [];
            foreach ($visitors as $doc) {
                $visitor = [
                    '_id' => (string)$doc['_id'],
                    'visitorNumber' => $doc['visitorNumber'] ?? null,
                    'firstName' => $doc['firstName'],
                    'lastName' => $doc['lastName'],
                    'bookingTimeslot' => $this->safeDateFormat($doc['bookingTimeslot'] ?? null),
                    'entryTimeslot' => $this->safeDateFormat($doc['entryTimeslot'] ?? null),
                    'exitTimeslot' => $this->safeDateFormat($doc['exitTimeslot'] ?? null),
                    'status' => $doc['status'],
                    'user' => (string)$doc['user'],
                ];

                $results[] = $visitor;
            }
            
            return $results;
        } catch (Exception $error) {
            error_log('Error finding user visitors: ' . $error->getMessage());
            throw $error;
        }
    }

    public function findAllVisitorsService() {
        try {
            $visitors = $this->visitorCollection->find();

            $results = [];
            foreach ($visitors as $doc) {
                $visitor = [
                    '_id' => (string)$doc['_id'],
                    'visitorNumber' => $doc['visitorNumber'] ?? null,
                    'firstName' => $doc['firstName'],
                    'lastName' => $doc['lastName'],
                    'bookingTimeslot' => $this->safeDateFormat($doc['bookingTimeslot'] ?? null),
                    'entryTimeslot' => $this->safeDateFormat($doc['entryTimeslot'] ?? null),
                    'exitTimeslot' => $this->safeDateFormat($doc['exitTimeslot'] ?? null),
                    'status' => $doc['status'],
                    'user' => (string)$doc['user'],
                ];

                $results[] = $visitor;
            }
            
            return $results;
        } catch (Exception $error) {
            error_log('Error finding visitors: ' . $error->getMessage());
            throw $error;
        }
    }

    public function updateVisitorService($visitorId, $visitorDetails) {
        try {
            $visitorToUpdate = $this->visitorCollection->findOneAndUpdate(
                ['_id' => new ObjectId($visitorId)],
                ['$set' => $visitorDetails],
                ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
            if (!$visitorToUpdate) {
                throw new Exception('Visitor not found');
            }
            return $visitorToUpdate;
        } catch (Exception $error) {
            error_log('Error updating visitor: ' . $error->getMessage());
            throw $error;
        }
    }

    public function deleteVisitorService($visitorId) {
        try {
            $visitor = $this->visitorCollection->findOne(['_id' => new ObjectId($visitorId)]);
            if (!$visitor) {
                throw new Exception('Visitor not found');
            }

            $this->userCollection->updateOne(
                ['_id' => new ObjectId($visitor['user'])],
                ['$pull' => ['visitors' => ['$in' => [new ObjectId($visitorId), null]]]]
            );

            $this->visitorCollection->deleteOne(['_id' => new ObjectId($visitorId)]);

            return true;
        } catch (Exception $error) {
            error_log('Error deleting visitor: ' . $error->getMessage());
            throw $error;
        }
    }
}