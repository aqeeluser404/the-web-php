<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
// require_once __DIR__ . '/../utils/imageKit.php';
require_once __DIR__ . '/../utils/LocalFileHelper.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class UnitService {
    private $unitCollection;
    private $rentalCollection;
    // private $ImageKitService;
    private $localFileHelper;

    public function __construct() {
        $db = Database::getDb();
        $this->unitCollection = $db->Unit;
        $this->rentalCollection = $db->Rental;
        // $this->ImageKitService = new ImageKitService();
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

    protected function unitChecks($unit) {

        $updateData = [];

        // Status check
        $updateData['unitStatus'] = ($unit['currentOccupants'] >= $unit['unitOccupants']) 
            ? 'Occupied' 
            : 'Available';

        // Access key checks
        if ($unit['currentOccupants'] == 0) {
            $updateData['accessKey.isShared'] = null;
            $updateData['accessKey.assignedKey'] = null;
            $updateData['genderAssignment'] = null;
        }

        // Validate gender assignment
        if (!empty($unit['genderAssignment'])) {
            $existingUnit = $this->unitCollection->findOne([
                'unitNumber' => $unit['unitNumber'],
                '_id' => ['$ne' => new ObjectId($unit['_id'])]
            ]);

            if ($existingUnit && $existingUnit['genderAssignment'] !== $unit['genderAssignment']) {
                throw new Exception("Gender restriction: This unit is only available for " . $existingUnit['genderAssignment'] . "s.");
            }
        }

        // Perform update
        $this->unitCollection->updateOne(
            ['_id' => new ObjectId($unit['_id'])],
            ['$set' => $updateData]
        );
    }

    public function createUnitService(array $unitDetails, array $unitImages) {
        try {
            if ($this->unitCollection->findOne(['unitNumber' => $unitDetails['unitNumber']])) {
                throw new Exception('Unit already exists');
            }
    
            $imageData = array_map(function($file) {
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    throw new Exception('Invalid image upload');
                }
                
                // $uploaded = $this->ImageKitService->uploadImage($file);
                $uploaded = $this->localFileHelper->uploadImage($file);
                return [
                    'imageUrl' => (string) $uploaded['imageUrl'],
                    'fileId' => (string) $uploaded['fileId'],
                    '_id' => new ObjectId()
                ];
            }, $unitImages['images']);
    
            // $unitDetails['unitNumber'] = (int)$unitDetails['unitNumber'];
            $unitDetails['unitNumber'] = (string)$unitDetails['unitNumber'];
            $unitDetails['floorLevel'] = (string)$unitDetails['floorLevel'];
            $unitDetails['unitType'] = (string)$unitDetails['unitType'];
            $unitDetails['unitOccupants'] = (int)$unitDetails['unitOccupants'];
            $unitDetails['unitDescription'] = (string)$unitDetails['unitDescription'];
            $unitDetails['unitPrice'] = (float)$unitDetails['unitPrice'];

            $unit = new Unit(
                unitNumber: $unitDetails['unitNumber'], 
                floorLevel: $unitDetails['floorLevel'],
                unitType: $unitDetails['unitType'],
                unitOccupants: $unitDetails['unitOccupants'],
                unitDescription: $unitDetails['unitDescription'],
                unitPrice: $unitDetails['unitPrice'],
                unitStatus: 'Available',
                genderAssignment: $unitDetails['genderAssignment'] ?? null,
                currentOccupants: 0,
                images: $imageData,
                rentedHistory: [],
                accessKey: ['isShared' => null, 'assignedKey' => null],
                // __v: 0
            );
    
            $result = $this->unitCollection->insertOne($unit->toArray());
            return $this->unitCollection->findOne(['_id' => $result->getInsertedId()]);
    
        } catch (Exception $e) {
            error_log('Service error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function findUnitByIdService($unitId) {
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

    public function findAllUnitsService(): array {
        try {
            $units = $this->unitCollection->find();
            $results = [];
            
            foreach ($units as $doc) {
                $unit = [
                    '_id' => (string)$doc['_id'],
                    'unitNumber' => $doc['unitNumber'] ?? null,
                    'floorLevel' => $doc['floorLevel'] ?? null,
                    'unitType' => $doc['unitType'] ?? null,
                    'unitOccupants' => $doc['unitOccupants'] ?? null,
                    'currentOccupants' => $doc['currentOccupants'] ?? null,
                    'unitDescription' => $doc['unitDescription'] ?? null,
                    'unitPrice' => $doc['unitPrice'] ?? null,
                    'unitStatus' => $doc['unitStatus'] ?? null,
                    'dateCreated' => $this->safeDateFormat($doc['dateCreated'] ?? null),
                    'genderAssignment' => $doc['genderAssignment'] ?? null,
                ];
    
                if (!empty($doc['images']) && $doc['images'] instanceof \MongoDB\Model\BSONArray) {
                    $unit['images'] = [];
                    foreach ($doc['images'] as $image) {
                        $unit['images'][] = [
                            'imageUrl' => $image['imageUrl'] ?? null,
                            'fileId' => $image['fileId'] ?? null,
                            '_id' => isset($image['_id']) ? (string)$image['_id'] : null
                        ];
                    }
                } else {
                    $unit['images'] = $doc['images'] ?? null;
                }
    
                $unit['rentedHistory'] = isset($doc['rentedHistory']) 
                    ? array_map(function($id) { return (string)$id; }, $doc['rentedHistory']->getArrayCopy())
                    : [];
    
                $unit['accessKey'] = [
                    'isShared' => $doc['accessKey']['isShared'] ?? false,
                    'assignedKey' => $doc['accessKey']['assignedKey'] ?? 0
                ];
    
                // RUN UNIT CHECK AND UPDATE
                $this->unitChecks($unit);
                
                $updatedDoc = $this->unitCollection->findOne(['_id' => $doc['_id']]);
                if ($updatedDoc) {
                    $unit['unitStatus'] = $updatedDoc['unitStatus'] ?? $unit['unitStatus'];
                    $unit['accessKey'] = $updatedDoc['accessKey'] ?? $unit['accessKey'];
                    $unit['genderAssignment'] = $updatedDoc['genderAssignment'] ?? $unit['genderAssignment'];
                }
    
                $results[] = $unit;
            }
            return $results;
        } catch (Exception $e) {
            throw $e;
        }
    }


    // public function updateUnitService($unitId, $unitDetails) {
    //     try {
    //         $existingUnit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
    //         if (!$existingUnit) {
    //             throw new Exception('Unit not found');
    //         }
    
    //         // Remove any restricted fields
    //         // unset($unitDetails['images'], $unitDetails['_id']);
    
    //         $this->unitCollection->updateOne(
    //             ['_id' => new ObjectId($unitId)],
    //             ['$set' => $unitDetails]
    //         );
    
    //         // RUN UNIT CHECK AND UPDATE
    //         $updatedUnit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
    //         $this->unitChecks($updatedUnit);
            
    //         return $updatedUnit;
            
    //     } catch (Exception $e) {
    //         throw $e;
    //     }
    // }

    
    public function updateUnitService(
        $unitId, 
        $unitDetails, 
        $unitImages = []
    ) {
        try {
            
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) throw new Exception('Unit not found');

            $imageData = [];
            if (!empty($unitImages['images'])) {
                foreach ($unit['images'] ?? [] as $image) {
                    $this->localFileHelper->deleteImage($image['fileId']);
                }
                $imageData = array_map(function($file) {
                    return $this->localFileHelper->uploadImage($file);
                }, $unitImages['images']);
            } else {
                $imageData = $unit['images'] ?? [];
            }

            $updateData = [
                'unitNumber' => (string) ($unitDetails['unitNumber'] ?? $unit['unitNumber']),
                'floorLevel' => (string) ($unitDetails['floorLevel'] ?? $unit['floorLevel']),
                'unitType' => (string) ($unitDetails['unitType'] ?? $unit['unitType']),
                'unitOccupants' => (int) ($unitDetails['unitOccupants'] ?? $unit['unitOccupants']),
                'unitDescription' => (string) ($unitDetails['unitDescription'] ?? $unit['unitDescription']),
                'unitPrice' => (float) ($unitDetails['unitPrice'] ?? $unit['unitPrice']),
                'genderAssignment' => $unitDetails['genderAssignment'] ?? $unit['genderAssignment'] ?? null,
                'images' => !empty($imageData) ? $imageData : $unit['images'], // Keep old images if no new ones
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

    public function deleteUnitService(string $unitId): bool {
        try {
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) throw new Exception('Unit not found');
    
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