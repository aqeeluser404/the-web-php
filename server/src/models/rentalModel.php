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

    /** @var ObjectId */
    public $user;

    /** @var string */
    public $accessKey;

    public function __construct(
        float $rentalPrice,
        string $unit,
        string $unitType,
        string $user,
        string $status = 'Pending',
        ?string $accessKey = null,
        ?string $rentalStartDate = null,
        ?string $rentalEndDate = null,
        ?string $earlyEndDate = null,
        array $payerData = []
    ) {
        $this->applicationDate = new UTCDateTime();
        $this->status = in_array($status, ['Pending', 'Rejected', 'Active', 'Ended']) ? $status : 'Pending';
        $this->rentalStartDate = $rentalStartDate ? new UTCDateTime(strtotime($rentalStartDate) * 1000) : null;
        $this->rentalEndDate = $rentalEndDate ? new UTCDateTime(strtotime($rentalEndDate) * 1000) : null;
        $this->earlyEndDate = $earlyEndDate ? new UTCDateTime(strtotime($earlyEndDate) * 1000) : null;
        $this->rentalPrice = $rentalPrice;
        $this->unit = new ObjectId($unit);
        $this->unitType = $unitType;
        $this->user = new ObjectId($user);
        $this->accessKey = $accessKey;

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
    }

    public function toArray(): array {
        return [
            'applicationDate' => $this->applicationDate,
            'status' => $this->status,
            'rentalStartDate' => $this->rentalStartDate,
            'rentalEndDate' => $this->rentalEndDate,
            'earlyEndDate' => $this->earlyEndDate,
            'rentalPrice' => $this->rentalPrice,
            'payerData' => $this->payerData,
            'unit' => $this->unit,
            'unitType' => $this->unitType,
            'user' => $this->user,
            'accessKey' => $this->accessKey
        ];
    }
}