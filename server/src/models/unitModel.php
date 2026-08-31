<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class Unit
{
    // /** @var int */
    /** @var string */
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
    
    /** 
     * @var int The year this unit is available for (e.g., 2026, 2027)
     */
    public $unitYear;

    /** 
     * @var array<array{type: string, roomType?: string, bedType?: string, price: float}> 
     * Optional nested rooms or beds
     */
    public $subUnits = [];

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

    /** 
     * @var ObjectId|null The user who reserved this unit 
     */
    public $reservedBy = null;

    /** 
     * @var UTCDateTime|null When the reservation was made 
     */
    public $reservedAt = null;

    public function __construct(
        // int $unitNumber,
        string $unitNumber,
        string $floorLevel,
        string $unitType,
        int $unitOccupants,
        string $unitDescription,
        float $unitPrice,
        int $unitYear,

        array $subUnits = [],

        string $unitStatus = 'Available',
        ?string $genderAssignment = null,
        int $currentOccupants = 0,
        array $images = [],
        array $rentedHistory = [],
        array $accessKey = [
            'isShared' => null,
            'assignedKey' => null,
            'createdAt' => null,
            'expiresAt' => null
        ],
        ?ObjectId $reservedBy = null,
        ?UTCDateTime $reservedAt = null
    ) {
        $this->unitNumber = $unitNumber;
        $this->floorLevel = $floorLevel;
        $this->unitType = $unitType;
        $this->unitOccupants = $unitOccupants;
        $this->currentOccupants = $currentOccupants;
        $this->unitDescription = $unitDescription;
        $this->unitPrice = $unitPrice;
        $this->unitYear = $unitYear;

        $this->subUnits = array_map(function ($subUnit) {
            return [
                'type' => $subUnit['type'] ?? 'room',
                'roomType' => $subUnit['roomType'] ?? null,
                'bedType' => $subUnit['bedType'] ?? null,
                'price' => array_map(function ($priceEntry) {
                    return [
                        'name' => $priceEntry['name'] ?? 'default',
                        'price' => floatval($priceEntry['price'] ?? 0)
                    ];
                }, is_array($subUnit['price']) ? $subUnit['price'] : [['price' => $subUnit['price'] ?? 0]]),
                'discount' => $subUnit['discount'] ?? null,
                'isAvailable' => $subUnit['isAvailable'] ?? true,
                'reservedBy' => $subUnit['reservedBy'] ?? null, 
                'reservedAt' => $subUnit['reservedAt'] ?? null 
            ];
        }, $subUnits);

        $this->unitStatus = $unitStatus;
        $this->genderAssignment = $genderAssignment;

        $this->images = array_map(function (array $image): array {
            return [
                'imageUrl' => $image['imageUrl'] ?? '',
                'fileId' => $image['fileId'] ?? '',
                '_id' => $image['_id'] ?? new ObjectId()
            ];
        }, $images);

        $this->dateCreated = new UTCDateTime();

        $this->rentedHistory = array_map(
            fn($id) => $id instanceof ObjectId ? $id : new ObjectId($id),
            $rentedHistory
        );

        $this->accessKey = [
            'isShared' => $accessKey['isShared'] ?? null,
            'assignedKey' => $accessKey['assignedKey'] ?? null,
            'createdAt' => $accessKey['createdAt'] ?? null,
            'expiresAt' => $accessKey['expiresAt'] ?? null
        ];

        $this->reservedBy = $reservedBy;
        $this->reservedAt = $reservedAt;
    }

    /**
     * Convert the Unit to a MongoDB document array
     */
    public function toArray(): array
    {
        return [
            'unitNumber' => $this->unitNumber,
            'floorLevel' => $this->floorLevel,
            'unitType' => $this->unitType,
            'unitOccupants' => $this->unitOccupants,
            'currentOccupants' => $this->currentOccupants,
            'unitDescription' => $this->unitDescription,
            'unitPrice' => $this->unitPrice,
            'unitYear' => $this->unitYear,
            'subUnits' => $this->subUnits,
            'unitStatus' => $this->unitStatus,
            'images' => $this->images,
            'dateCreated' => $this->dateCreated,
            'rentedHistory' => $this->rentedHistory,
            'genderAssignment' => $this->genderAssignment,
            'accessKey' => $this->accessKey,
        ];

        if ($this->reservedBy !== null) {
            $data['reservedBy'] = $this->reservedBy;
        }
        if ($this->reservedAt !== null) {
            $data['reservedAt'] = $this->reservedAt;
        }

        return $data;
    }
}
