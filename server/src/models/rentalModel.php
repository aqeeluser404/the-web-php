<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class Rental {

    /** @var UTCDateTime */
    public $applicationDate;

    /** @var string */
    public $status;

    /** @var UTCDateTime */
    public $rentalStartDate;

    /** @var UTCDateTime */
    public $rentalEndDate;

    /** @var UTCDateTime */
    public $earlyEndDate;

    /** @var float */
    public $rentalPrice;

    /** @var array{firstName: string, lastName: string, email: string, idNumber: string, bankName: string, salary: float, score: int, isValidated: bool} */
    public $payerData;

    /** @var ObjectId */
    public $unit;

    /** @var string */
    public $unitType;

    /** 
     * @var array|null The selected sub-unit(s) for this rental, with type and price 
     */
    public $selectedSubUnits = null;

    /** @var ObjectId */
    public $user;

    /** @var string */
    public $accessKey;

    /** @var array{selected: bool, fee: float} */
    public $parking;
    
    /** @var array{selected: bool, fee: float} */
    public $shuttle;

    /** @var array{imageUrl: string, fileId: string, _id: ObjectId}|null */
    public $guardianSignature;

     /** @var array{imageUrl: string, fileId: string, _id: ObjectId}|null */
    public $signature;

    /** 
     * @var array<int, array{documentUrl: string, fileId: string, uploadDate: UTCDateTime, docType: string, _id: ObjectId}> 
     */
    public $documents;
    
    /** 
     * @var array|null Signing tokens for tenant and guardian 
     */
    public $signingTokens = null;
    
    /** 
     * @var string|null Optional Trafalgar ID for the rental 
     */
    public $trafalgarId = null;
    
    /** 
     * @var int|null Optional Unit Year for the rental 
     */
    public $unitYear = null;

    public function __construct(
        float $rentalPrice,
        string $unit,
        string $unitType,
        string $user,
        ?array $selectedSubUnits = null,
        string $status = 'Pending',
        ?string $accessKey = null,
        ?string $rentalStartDate = null,
        ?string $rentalEndDate = null,
        ?string $earlyEndDate = null,
        array $payerData = [],
        ?array $parking = null,
        ?array $shuttle = null,
        ?array $signature = null,
        ?array $guardianSignature = null,
        ?array $documents = null,
        ?array $signingTokens = null,
        ?string $trafalgarId = null,
        ?int $unitYear = null
    ) {
        $this->applicationDate = new UTCDateTime();
        $this->status = in_array($status, ['Pending', 'Rejected', 'Active', 'Ended']) ? $status : 'Pending';
        $this->rentalStartDate = $rentalStartDate ? new UTCDateTime(strtotime($rentalStartDate) * 1000) : null;
        $this->rentalEndDate = $rentalEndDate ? new UTCDateTime(strtotime($rentalEndDate) * 1000) : null;
        $this->earlyEndDate = $earlyEndDate ? new UTCDateTime(strtotime($earlyEndDate) * 1000) : null;
        $this->rentalPrice = $rentalPrice;
        $this->unit = new ObjectId($unit);
        $this->unitType = $unitType;
        $this->selectedSubUnits = $selectedSubUnits;
        $this->user = new ObjectId($user);
        $this->accessKey = $accessKey;
        $this->trafalgarId = $trafalgarId;
        $this->unitYear = $unitYear;

        // Ensure payerData has default values in the correct order
        $this->payerData = array_merge([
            'firstName' => '',
            'lastName' => '',
            'email' => '',
            'idNumber' => '',
            'bankName' => '',
            'salary' => 0.0,
            'score' => 0,
            'isValidated' => false
        ], $payerData);

        // Default parking data if not provided
        $this->parking = array_merge([
            'hasParking' => false,
            'fee' => 50.0 // Placeholder fee
        ], $parking ?? []);
        
        $this->shuttle = array_merge([
            'hasShuttle' => false,
            'fee' => 50.0 // Placeholder fee
        ], $shuttle ?? []);

        // $this->signature = array_merge([
        //     'imageUrl' => $image['imageUrl'] ?? '',
        //     'fileId' => $image['fileId'] ?? '',
        //     '_id' => $image['_id'] ?? new ObjectId()
        // ], $signature)

        $this->signature = $signature ? [
            'imageUrl' => $signature['imageUrl'] ?? '',
            'fileId' => $signature['fileId'] ?? '',
            '_id' => $signature['_id'] ?? new ObjectId()
        ] : null;

        $this->guardianSignature = $guardianSignature ? [
            'imageUrl' => $guardianSignature['imageUrl'] ?? '',
            'fileId' => $guardianSignature['fileId'] ?? '',
            '_id' => $guardianSignature['_id'] ?? new ObjectId()
        ] : null;

        $this->documents = array_map(function($doc) {
            return array_merge([
                'documentUrl' => null,
                'fileId'      => null,
                'uploadDate'  => new UTCDateTime(),
                'docType'     => null, 
            ], $doc);
        }, $documents ?? []);
        
        $this->signingTokens = $signingTokens;
    }

    public function toArray(): array {
        $array = [
            'applicationDate' => $this->applicationDate,
            'status' => $this->status,
            'rentalStartDate' => $this->rentalStartDate,
            'rentalEndDate' => $this->rentalEndDate,
            'earlyEndDate' => $this->earlyEndDate,
            'rentalPrice' => $this->rentalPrice,
            'payerData' => $this->payerData,
            'unit' => $this->unit,
            'unitType' => $this->unitType,
            'selectedSubUnits' => $this->selectedSubUnits,
            'user' => $this->user,
            'accessKey' => $this->accessKey,
            'parking' => $this->parking,
            'shuttle' => $this->shuttle,
            'signature' => $this->signature,
            'guardianSignature' => $this->guardianSignature
        ];
    
        if (!empty($this->documents)) {
            $array['documents'] = $this->documents;
        }
    
        if (!empty($this->signingTokens)) {
            $array['signingTokens'] = $this->signingTokens;
        }
    
        if ($this->trafalgarId !== null) {
            $array['trafalgarId'] = $this->trafalgarId;
        }
    
        if ($this->unitYear !== null) {
            $array['unitYear'] = $this->unitYear;
        }
    
        return $array;
    }
}