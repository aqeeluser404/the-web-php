<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../utils/LocalFileHelper.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class ApplicationDraftService
{
    private $applicationDraftCollection;
    private $userCollection;
    private $localFileHelper;
    private $rentalCollection;

    public function __construct()
    {
        $db = Database::getDb();
        $this->applicationDraftCollection = $db->ApplicationDraft;
        $this->rentalCollection = $db->Rental;
        $this->userCollection = $db->User;
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

    public function saveApplicationDraftService(array $data)
    {
        try {
            $userId = $data['userId'] ?? null;
            if (!$userId) {
                throw new Exception('User ID is required');
            }
    
            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                throw new Exception('User not found');
            }
    
            $existingDraft = $this->applicationDraftCollection->findOne([
                'userId' => new ObjectId($userId),
                'status' => 'draft'
            ]);
    
            // Build draft array directly (no model class)
            $draftArray = [
                'userId' => new ObjectId($userId),
                'rentalId' => isset($data['rentalId']) ? new ObjectId($data['rentalId']) : null,
                'status' => 'draft',
                'createdAt' => new UTCDateTime(),
                'updatedAt' => new UTCDateTime(),
                'studentInfo' => $data['studentInfo'] ?? [],
                'parentInfo' => $data['parentInfo'] ?? [],
                'paymentInfo' => $data['paymentInfo'] ?? [],
                'signatureInfo1' => $data['signatureInfo1'] ?? [],
                'signatureInfo2' => $data['signatureInfo2'] ?? [],
                'prospectiveInfo' => $data['prospectiveInfo'] ?? [],
                'signatures' => $data['signatures'] ?? [],
                'file' => $data['file'] ?? null
            ];
    
            if ($existingDraft) {
                $draftArray['updatedAt'] = new UTCDateTime();
                unset($draftArray['createdAt']);
    
                $updateResult = $this->applicationDraftCollection->updateOne(
                    ['_id' => $existingDraft['_id']],
                    ['$set' => $draftArray]
                );
    
                if ($updateResult->getModifiedCount() === 0) {
                    throw new Exception('Failed to update draft');
                }
    
                $draftId = (string) $existingDraft['_id'];
                $message = 'Draft updated successfully';
            } else {
                $insertResult = $this->applicationDraftCollection->insertOne($draftArray);
    
                if ($insertResult->getInsertedCount() === 0) {
                    throw new Exception('Failed to save draft');
                }
    
                $draftId = (string) $insertResult->getInsertedId();
                $message = 'Draft saved successfully';
            }
    
            return [
                'success' => true,
                'draftId' => $draftId,
                'message' => $message,
                'updatedAt' => $this->safeDateFormat(new UTCDateTime())
            ];
    
        } catch (Exception $e) {
            error_log('Save application draft error: ' . $e->getMessage());
            throw new Exception('Failed to save draft: ' . $e->getMessage());
        }
    }

    public function getApplicationDraftService(string $userId)
    {
        try {
            if (!preg_match('/^[a-f\d]{24}$/i', $userId)) {
                throw new Exception('Invalid user ID format');
            }

            $draft = $this->applicationDraftCollection->findOne([
                'userId' => new ObjectId($userId),
                'status' => 'draft'
            ]);

            if (!$draft) {
                return null;
            }

            return [
                '_id' => (string) $draft['_id'],
                'userId' => (string) $draft['userId'],
                'rentalId' => isset($draft['rentalId']) ? (string) $draft['rentalId'] : null,
                'studentInfo' => $draft['studentInfo'] ?? [],
                'parentInfo' => $draft['parentInfo'] ?? [],
                'paymentInfo' => $draft['paymentInfo'] ?? [],
                'signatureInfo1' => $draft['signatureInfo1'] ?? [],
                'signatureInfo2' => $draft['signatureInfo2'] ?? [],
                'prospectiveInfo' => $draft['prospectiveInfo'] ?? [],
                'signatures' => $draft['signatures'] ?? [],
                'file' => $draft['file'] ?? null,
                'createdAt' => $this->safeDateFormat($draft['createdAt'] ?? null),
                'updatedAt' => $this->safeDateFormat($draft['updatedAt'] ?? null)
            ];

        } catch (Exception $e) {
            error_log('Get draft error: ' . $e->getMessage());
            throw new Exception('Failed to get draft: ' . $e->getMessage());
        }
    }
    
    public function getAllDraftsService()
    {
        try {
            $drafts = $this->applicationDraftCollection->find([
                'status' => 'draft'
            ]);

            $results = [];
            foreach ($drafts as $draft) {
                $results[] = [
                    '_id' => (string) $draft['_id'],
                    'userId' => (string) $draft['userId'],
                    'rentalId' => isset($draft['rentalId']) ? (string) $draft['rentalId'] : null,
                    'status' => $draft['status'] ?? 'draft',
                    'studentInfo' => [
                        'firstName' => $draft['studentInfo']['firstName'] ?? '',
                        'surname' => $draft['studentInfo']['surname'] ?? '',
                        'email' => $draft['studentInfo']['email'] ?? '',
                        'studentNumber' => $draft['studentInfo']['studentNumber'] ?? ''
                    ],
                    'parentInfo' => [
                        'firstName' => $draft['parentInfo']['firstName'] ?? '',
                        'surname' => $draft['parentInfo']['surname'] ?? '',
                        'email' => $draft['parentInfo']['email'] ?? ''
                    ],
                    'hasSignatures' => !empty($draft['signatures']) && count(array_filter((array)$draft['signatures'])) > 0,
                    'createdAt' => $this->safeDateFormat($draft['createdAt'] ?? null),
                    'updatedAt' => $this->safeDateFormat($draft['updatedAt'] ?? null)
                ];
            }

            return $results;

        } catch (Exception $e) {
            error_log('Get all drafts error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function deleteApplicationDraftService(string $userId)
    {
        try {
            if (!preg_match('/^[a-f\d]{24}$/i', $userId)) {
                throw new Exception('Invalid user ID format');
            }

            $deleteResult = $this->applicationDraftCollection->deleteOne([
                'userId' => new ObjectId($userId),
                'status' => 'draft'
            ]);

            return [
                'success' => true,
                'deletedCount' => $deleteResult->getDeletedCount(),
                'message' => $deleteResult->getDeletedCount() > 0
                    ? 'Draft deleted successfully'
                    : 'No draft found to delete'
            ];

        } catch (Exception $e) {
            error_log('Delete draft error: ' . $e->getMessage());
            throw new Exception('Failed to delete draft: ' . $e->getMessage());
        }
    }
}