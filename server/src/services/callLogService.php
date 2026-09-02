<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../utils/LocalFileHelper.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;

class CallLogService {
    private $callLogCollection;
    private $userCollection;
    private $localFileHelper;

    public function __construct() {
        $db = Database::getDb();
        $this->callLogCollection = $db->calllogs;
        $this->userCollection = $db->User;
        $this->localFileHelper = new LocalFileHelper();
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

    // ─── HANDLERS ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function createCallLogService(array $callLogDetails, array $callLogImages = []): array {
        try {
            if (empty($callLogDetails['callType']) || empty($callLogDetails['user'])) {
                throw new Exception('Missing required fields: callType and user are required');
            }
            $attempts = 0;
            do {
                $logNumber = $this->generateCustomId();
                $existingCall = $this->callLogCollection->findOne(['logNumber' => $logNumber]);
                if ($existingCall && $attempts++ > 3) {
                    error_log("Multiple collisions generating logNumber");
                }
            } while ($existingCall !== null);

            $imageData = [];
            if (!empty($callLogImages['images'])) {
                $imageData = array_map(function ($file) {
                    if ($file->getError() !== UPLOAD_ERR_OK) {
                        throw new Exception('Invalid image upload');
                    }
                    $uploaded = $this->localFileHelper->uploadImage($file);
                    return [
                        '_id'      => new ObjectId(),
                        'imageUrl' => (string) $uploaded['imageUrl'],
                        'fileId'   => (string) $uploaded['fileId']
                    ];
                }, $callLogImages['images']);
            }
            $callLogData = [
                'logNumber'   => $logNumber,
                'callType'    => $callLogDetails['callType'],
                'status'      => 'Opened',
                'createdAt'   => new UTCDateTime(),
                'user'        => new ObjectId($callLogDetails['user']),
                'unit'        => $callLogDetails['unit'] ?? null,
                'description' => $callLogDetails['description'] ?? null,
                'summary'     => $callLogDetails['summary'] ?? null,
                'images'      => $imageData,
                'updates'     => [] 
            ];
            $insertResult = $this->callLogCollection->insertOne($callLogData);
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
            if (empty($user['callLogs'])) {
                return [];
            }
            $callLogs = $this->callLogCollection->find([
                '_id' => ['$in' => $user['callLogs']]
            ]);
            $results = [];
            foreach ($callLogs as $doc) {
                $callLog = [
                    '_id'        => (string)$doc['_id'],
                    'logNumber'  => $doc['logNumber'] ?? null,
                    'callType'   => $doc['callType'] ?? null,
                    'status'     => $doc['status'] ?? null,
                    'createdAt'  => $this->safeDateFormat($doc['createdAt'] ?? null),
                    'closedAt'   => $this->safeDateFormat($doc['closedAt'] ?? null),
                    'user'       => isset($doc['user']) ? (string)$doc['user'] : null,
                    'unit'       => $doc['unit'] ?? null,
                    'description'=> $doc['description'] ?? null,
                    'summary'    => $doc['summary'] ?? null,
                ];
                if (!empty($doc['images']) && $doc['images'] instanceof \MongoDB\Model\BSONArray) {
                    $callLog['images'] = [];
                    foreach ($doc['images'] as $image) {
                        $callLog['images'][] = [
                            'imageUrl' => $image['imageUrl'] ?? null,
                            'fileId'   => $image['fileId'] ?? null,
                            '_id'      => isset($image['_id']) ? (string)$image['_id'] : null
                        ];
                    }
                } else {
                    $callLog['images'] = $doc['images'] ?? [];
                }
                // Updates (optional timeline array)
                if (!empty($doc['updates']) && $doc['updates'] instanceof \MongoDB\Model\BSONArray) {
                    $callLog['updates'] = [];
                    foreach ($doc['updates'] as $update) {
                        $callLog['updates'][] = [
                            'updateInfo' => $update['updateInfo'] ?? null,
                            'addedAt'    => $this->safeDateFormat($update['addedAt'] ?? null),
                            'user'     => $update['user'] ?? null
                        ];
                    }
                } else {
                    $callLog['updates'] = $doc['updates'] ?? [];
                }
                $callLog['vendorInfo'] = isset($doc['vendorInfo']) ? [
                    'vendorType'        => $doc['vendorInfo']['vendorType'] ?? null,
                    'vendorContact'     => $doc['vendorInfo']['vendorContact'] ?? null,
                    'vendorAssignedDate'=> $this->safeDateFormat($doc['vendorInfo']['vendorAssignedDate'] ?? null)
                ] : null;
                $callLog['vendorNotes'] = isset($doc['vendorNotes']) ? [
                    'notes'      => $doc['vendorNotes']['notes'] ?? null,
                    'resolution' => $doc['vendorNotes']['resolution'] ?? null,
                ] : null;
                $results[] = $callLog;
            }
            return $results;
        } catch (Exception $error) {
            error_log('Error finding user call logs: ' . $error->getMessage());
            throw $error;
        }
    }

    public function updateCallLogService($callLogId, $callLogDetails) {
        try {
            $existingCallLog = $this->callLogCollection->findOne(['_id' => new ObjectId($callLogId)]);
            if (!$existingCallLog) {
                throw new Exception('Call log not found');
            }

            // Normalize existing updates to a plain array regardless of BSON type
            $existingUpdates = isset($existingCallLog['updates'])
                ? (array) $existingCallLog['updates']
                : [];

            if (!empty($callLogDetails['updates']) && is_array($callLogDetails['updates'])) {
                $newUpdates = array_map(function ($u) {
                    return [
                        '_id'        => new ObjectId(),
                        'updateInfo' => is_array($u) ? ($u['updateInfo'] ?? null) : null,
                        'addedAt'    => new UTCDateTime(),
                        'user'       => is_array($u) ? ($u['user'] ?? null) : null,
                    ];
                }, $callLogDetails['updates']);

                $callLogDetails['updates'] = array_merge($existingUpdates, $newUpdates);
            } else {
                $callLogDetails['updates'] = $existingUpdates;
            }

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

    public function findAllCallLogsService() {
        try {
            $callLogs = $this->callLogCollection->find();
            $results = [];
            foreach ($callLogs as $doc) {
                $callLog = [
                    '_id'        => (string)$doc['_id'],
                    'logNumber'  => $doc['logNumber'] ?? null,
                    'callType'   => $doc['callType'] ?? null,
                    'status'     => $doc['status'] ?? null,
                    'createdAt'  => $this->safeDateFormat($doc['createdAt'] ?? null),
                    'closedAt'   => $this->safeDateFormat($doc['closedAt'] ?? null),
                    'user'       => isset($doc['user']) ? (string)$doc['user'] : null,
                    'unit'       => $doc['unit'] ?? null,
                    'description'=> $doc['description'] ?? null,
                    'summary'    => $doc['summary'] ?? null,
                ];
                if (!empty($doc['images']) && $doc['images'] instanceof \MongoDB\Model\BSONArray) {
                    $callLog['images'] = [];
                    foreach ($doc['images'] as $image) {
                        $callLog['images'][] = [
                            'imageUrl' => $image['imageUrl'] ?? null,
                            'fileId'   => $image['fileId'] ?? null,
                            '_id'      => isset($image['_id']) ? (string)$image['_id'] : null
                        ];
                    }
                } else {
                    $callLog['images'] = $doc['images'] ?? [];
                }
                // Updates (optional timeline array)
                if (!empty($doc['updates']) && $doc['updates'] instanceof \MongoDB\Model\BSONArray) {
                    $callLog['updates'] = [];
                    foreach ($doc['updates'] as $update) {
                        $callLog['updates'][] = [
                            'updateInfo' => $update['updateInfo'] ?? null,
                            'addedAt'    => $this->safeDateFormat($update['addedAt'] ?? null),
                            'user'     => $update['user'] ?? null
                        ];
                    }
                } else {
                    $callLog['updates'] = $doc['updates'] ?? [];
                }
                $callLog['vendorInfo'] = isset($doc['vendorInfo']) ? [
                    'vendorType'        => $doc['vendorInfo']['vendorType'] ?? null,
                    'vendorContact'     => $doc['vendorInfo']['vendorContact'] ?? null,
                    'vendorAssignedDate'=> $this->safeDateFormat($doc['vendorInfo']['vendorAssignedDate'] ?? null)
                ] : null;
                $callLog['vendorNotes'] = isset($doc['vendorNotes']) ? [
                    'notes'      => $doc['vendorNotes']['notes'] ?? null,
                    'resolution' => $doc['vendorNotes']['resolution'] ?? null,
                ] : null;
                $results[] = $callLog;
            }
            return $results;
        } catch (Exception $error) {
            error_log('Error finding call logs: ' . $error->getMessage());
            throw $error;
        }
    }

    public function deleteCallLogService($callLogId) {
        try {
            $callLog = $this->callLogCollection->findOne(['_id' => new ObjectId($callLogId)]);
            if (!$callLog) {
                throw new Exception('Call log not found');
            }
            foreach ($callLog['images'] ?? [] as $image) {
                try {
                    $this->localFileHelper->deleteImage($image['fileId']);

                } catch (Exception $e) {
                    error_log("Failed to delete image {$image['fileId']}: " . $e->getMessage());
                }
            }
            $this->userCollection->updateOne(
                ['_id' => new ObjectId($callLog['user'])],
                ['$pull' => ['callLogs' => ['$in' => [new ObjectId($callLogId), null]]]]
            );
            $this->callLogCollection->deleteOne(['_id' => new ObjectId($callLogId)]);
            return true;
        } catch (Exception $error) {
            error_log('Error deleting call log: ' . $error->getMessage());
            throw $error;
        }
    }
    
    public function deleteCallLogUpdateService($callLogId, $updateInfo, $addedAt) {
        try {
            $dateTime = new DateTime($addedAt);
            $millis = $dateTime->getTimestamp() * 1000 + (int) $dateTime->format('v');

            $callLogToUpdate = $this->callLogCollection->findOneAndUpdate(
                ['_id' => new ObjectId($callLogId)],
                ['$pull' => [
                    'updates' => [
                        'updateInfo' => $updateInfo,
                        'addedAt'    => new UTCDateTime($millis)
                    ]
                ]],
                ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
            if (!$callLogToUpdate) {
                throw new Exception('Call log not found');
            }
            return $callLogToUpdate;
        } catch (Exception $error) {
            error_log('Error deleting call log update: ' . $error->getMessage());
            throw $error;
        }
    }
}