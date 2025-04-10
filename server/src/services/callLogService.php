<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;
use MongoDB\Operation\FindOneAndUpdate;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class CallLogService {
    private $callLogCollection;
    private $userCollection;

    public function __construct() {
        $db = Database::getDb();
        $this->callLogCollection = $db->calllogs;
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

    public function createCallLogService(array $callLogDetails): array {
        try {
            if (empty($callLogDetails['callType']) || empty($callLogDetails['user'])) {
                throw new Exception('Missing required fields: callType and user are required');
            }
    
            $callLogData = [
                'callType' => $callLogDetails['callType'],
                'status' => 'Pending',
                'createdAt' => new UTCDateTime(),
                'user' => new ObjectId($callLogDetails['user']),
            ];
    
            $insertResult = $this->callLogCollection->insertOne($callLogData);
    
            // UPDATE FK FIELD - ADD CALL LOG TO USER
            $this->userCollection->updateOne(
                ['_id' => new ObjectId($callLogDetails['user'])],
                ['$push' => ['callLogs' => $insertResult->getInsertedId()]]
            );

            return $callLogData;
        } catch (Exception $error) {
            error_log('Call log creation failed: ' . $error->getMessage());
            throw $error;
        }
    }

    public function findCallLogByIdService($id) {
        try {
            $callLog = $this->callLogCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$callLog) {
                throw new Exception('Call log not found');
            }
            return $callLog;
        } catch (Exception $error) {
            error_log('Error finding call log by ID: ' . $error->getMessage());
            throw $error;
        }
    }

    public function findAllMyCallLogsService($userId) {
        try {
            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                throw new Exception('User not found');
            }
    
            $callLogs = $this->callLogCollection->find(['_id' => ['$in' => $user['callLogs']]]);
            
            $results = [];
            foreach ($callLogs as $doc) {
                $results[] = [
                    '_id' => (string)$doc['_id'],
                    'callType' => $doc['callType'],
                    'status' => $doc['status'],
                    'createdAt' => $this->safeDateFormat($doc['createdAt'] ?? null),
                    'user' => (string)$doc['user'],
                ];
            }
            
            return $results;
        } catch (Exception $error) {
            error_log('Error finding user call logs: ' . $error->getMessage());
            throw $error;
        }
    }

    public function findAllCallLogsService() {
        try {
            $callLogs = $this->callLogCollection->find();
            
            $results = [];
            foreach ($callLogs as $doc) {
                $results[] = [
                    '_id' => (string)$doc['_id'],
                    'callType' => $doc['callType'],
                    'status' => $doc['status'],
                    'createdAt' => $this->safeDateFormat($doc['createdAt'] ?? null),
                    'user' => (string)$doc['user']
                ];
            }
            
            return $results;
        } catch (Exception $error) {
            error_log('Error finding call logs: ' . $error->getMessage());
            throw $error;
        }
    }

    public function updateCallLogService($callLogId, $callLogDetails) {
        try {
            $callLogToUpdate = $this->callLogCollection->findOneAndUpdate(
                ['_id' => new ObjectId($callLogId)],
                ['$set' => $callLogDetails],
                ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
            if (!$callLogToUpdate) {
                throw new Exception('Call log not found');
            }
            return $callLogToUpdate;
        } catch (Exception $error) {
            error_log('Error updating call log: ' . $error->getMessage());
            throw $error;
        }
    }

    public function deleteCallLogService($callLogId) {
        try {
            $callLog = $this->callLogCollection->findOne(['_id' => new ObjectId($callLogId)]);
            if (!$callLog) {
                throw new Exception('Call log not found');
            }

            // UPDATE FK FIELD - Remove call log from user's callLogs
            $this->userCollection->updateOne(
                ['_id' => $callLog['user']],
                [ '$pull' => ['callLogs' => new ObjectId($callLogId)]]
            );
            $this->userCollection->updateOne(
                ['_id' => $callLog['user']],
                ['$pull' => ['callLogs' => null]]
            );

            $this->callLogCollection->deleteOne(['_id' => new ObjectId($callLogId)]);

            return true;
        } catch (Exception $error) {
            error_log('Error deleting call log: ' . $error->getMessage());
            throw $error;
        }
    }
}