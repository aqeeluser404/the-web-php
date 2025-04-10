<?php
// require_once __DIR__ . '/../../../vendor/autoload.php';

// use MongoDB\BSON\ObjectId;
// use MongoDB\BSON\UTCDateTime;

// class Unit {
//     public $unitNumber;
//     public $floorLevel;
//     public $unitType;
//     public $unitOccupants;
//     public $currentOccupants;
//     public $unitDescription;
//     public $unitPrice;
//     public $unitStatus;
//     public $images;
//     public $dateCreated;
//     public $rentedHistory;
//     public $genderAssignment;
//     public $accessKey;

//     public function __construct(
//         $unitNumber,                 
//         $floorLevel,                  
//         $unitType,                      
//         $unitOccupants,                 
//         $unitDescription,            
//         $unitPrice,                     
//         $unitStatus,                  
//         $dateCreated,
//         $currentOccupants = 0, 
//         $images = [],
//         $rentedHistory = [],
//         $genderAssignment = null,
//         $accessKey = ['isShared' => false, 'assignedKey' => null]
//     ) {
//         $this->unitNumber = $unitNumber;
//         $this->floorLevel = $floorLevel;
//         $this->unitType = $unitType;
//         $this->unitOccupants = $unitOccupants;
//         $this->currentOccupants = $currentOccupants;
//         $this->unitDescription = $unitDescription;
//         $this->unitPrice = $unitPrice;
//         $this->unitStatus = $unitStatus;  

//         $this->images = array_map(function($image) {
//             return array_merge([
//                 'imageUrl' => null,
//                 'fileId' => null,
//                 '_id' => isset($image['_id']) ? $image['_id'] : new ObjectId()
//             ], $image);
//         }, $images);

//         $this->dateCreated = new UTCDateTime();

//         $this->rentedHistory = array_map(
//             function($id) { return new ObjectId($id); },
//             $rentedHistory
//         );

//         $this->accessKey = $accessKey;
//         $this->genderAssignment = $genderAssignment;
//     }
// }

require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class Unit {
    /** @var int */
    public $unitNumber;
    
    /** @var string */
    public $floorLevel;
    
    /** @var string */
    public $unitType;
    
    /** @var int */
    public $unitOccupants;
    
    /** @var int */
    public $currentOccupants;
    
    /** @var string */
    public $unitDescription;
    
    /** @var float */
    public $unitPrice;
    
    /** @var string */
    public $unitStatus;
    
    /** 
     * @var array<array{imageUrl: string, fileId: string, _id: ObjectId}> 
     */
    public $images;
    
    /** @var UTCDateTime */
    public $dateCreated;
    
    /** @var ObjectId[] */
    public $rentedHistory;
    
    /** @var string|null */
    public $genderAssignment;
    
    /** @var array{isShared: bool|null, assignedKey: string|null} */
    public $accessKey;
    
    // /** @var int */
    // public $__v;

    public function __construct(
        int $unitNumber,
        string $floorLevel,
        string $unitType,
        int $unitOccupants,
        string $unitDescription,
        float $unitPrice,
        string $unitStatus = 'Available',
        ?string $genderAssignment = null,
        int $currentOccupants = 0,
        array $images = [],
        array $rentedHistory = [],
        array $accessKey = ['isShared' => null, 'assignedKey' => null],
        // int $__v = 0,
    ) {
        $this->unitNumber = $unitNumber;
        $this->floorLevel = $floorLevel;
        $this->unitType = $unitType;
        $this->unitOccupants = $unitOccupants;
        $this->currentOccupants = $currentOccupants;
        $this->unitDescription = $unitDescription;
        $this->unitPrice = $unitPrice;
        $this->unitStatus = $unitStatus;
        $this->genderAssignment = $genderAssignment;

        // Process images array
        $this->images = array_map(function(array $image): array {
            return [
                'imageUrl' => $image['imageUrl'] ?? '',
                'fileId' => $image['fileId'] ?? '',
                '_id' => $image['_id'] ?? new ObjectId()
            ];
        }, $images);

        $this->dateCreated = new UTCDateTime();

        // Process rentedHistory array
        $this->rentedHistory = array_map(
            fn($id) => $id instanceof ObjectId ? $id : new ObjectId($id),
            $rentedHistory
        );

        $this->accessKey = [
            'isShared' => $accessKey['isShared'] ?? null,
            'assignedKey' => $accessKey['assignedKey'] ?? null
        ];

        // $this->__v = $__v;
    }

    /**
     * Convert the Unit to a MongoDB document array
     */
    public function toArray(): array {
        return [
            'unitNumber' => $this->unitNumber,
            'floorLevel' => $this->floorLevel,
            'unitType' => $this->unitType,
            'unitOccupants' => $this->unitOccupants,
            'currentOccupants' => $this->currentOccupants,
            'unitDescription' => $this->unitDescription,
            'unitPrice' => $this->unitPrice,
            'unitStatus' => $this->unitStatus,
            'images' => $this->images,
            'dateCreated' => $this->dateCreated,
            'rentedHistory' => $this->rentedHistory,
            'genderAssignment' => $this->genderAssignment,
            'accessKey' => $this->accessKey,
            // '__v' => $this->__v
        ];
    }
}
