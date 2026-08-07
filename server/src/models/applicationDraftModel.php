<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class ApplicationDraft {
    
    /** @var ObjectId */
    public $userId;

    /** @var ObjectId|null */
    public $rentalId;

    /** @var string */
    public $status;

    /** @var UTCDateTime */
    public $createdAt;

    /** @var UTCDateTime */
    public $updatedAt;

    /** @var array */
    public $studentInfo;

    /** @var array */
    public $parentInfo;

    /** @var array */
    public $paymentInfo;

    /** @var array */
    public $signatureInfo1;

    /** @var array */
    public $signatureInfo2;

    /** @var array */
    public $prospectiveInfo;

    /** @var array */
    public $signatures;

    /** @var array|null */
    public $file;

    public function __construct(
        string $userId,
        ?string $rentalId = null,
        array $studentInfo = [],
        array $parentInfo = [],
        array $paymentInfo = [],
        array $signatureInfo1 = [],
        array $signatureInfo2 = [],
        array $prospectiveInfo = [],
        array $signatures = [],
        ?array $file = null,
        string $status = 'draft'
    ) {
        $this->userId = new ObjectId($userId);
        $this->rentalId = $rentalId ? new ObjectId($rentalId) : null;
        $this->status = in_array($status, ['draft', 'complete']) ? $status : 'draft';
        $this->createdAt = new UTCDateTime();
        $this->updatedAt = new UTCDateTime();
        
        // Set default values for each section
        $this->studentInfo = array_merge([
            'studentNumber' => '',
            'title' => '',
            'firstName' => '',
            'surname' => '',
            'dateOfBirth' => '',
            'nationality' => '',
            'idNumber' => '',
            'passportNumber' => '',
            'maritalStatus' => '',
            'email' => '',
            'telephoneNumber' => '',
            'residentialAddress' => '',
            'postalAddress' => '',
            'theWeb' => true,
            'helshoogte' => false,
            'botmaskop' => false
        ], $studentInfo);

        $this->parentInfo = array_merge([
            'firstName' => '',
            'surname' => '',
            'phone' => '',
            'fax' => '',
            'email' => '',
            'employersName' => '',
            'employersAddress' => '',
            'occupation' => '',
            'monthlyIncome' => '',
            'periodEmployed' => ''
        ], $parentInfo);

        $this->paymentInfo = array_merge([
            'firstName' => '',
            'surname' => '',
            'idNumber' => '',
            'phone' => '',
            'email' => '',
            'telephoneNumber' => '',
            'residentialAddress' => '',
            'postalAddress' => '',
            'bank' => '',
            'bankName' => '',
            'branchCode' => '',
            'accountNumber' => '',
            'typeOfAccount' => ''
        ], $paymentInfo);

        $this->signatureInfo1 = array_merge([
            'nameAndTitle' => '',
            'date' => ''
        ], $signatureInfo1);

        $this->signatureInfo2 = array_merge([
            'nameAndTitle' => '',
            'date' => ''
        ], $signatureInfo2);

        $this->prospectiveInfo = array_merge([
            'prospectiveStudentName' => '',
            'nameOfParentOrGuardian' => '',
            'dateAt' => '',
            'onThis' => '',
            'dayOf' => '',
            'year' => ''
        ], $prospectiveInfo);

        $this->signatures = $signatures;
        $this->file = $file;
    }

    public function toArray(): array {
        return [
            'userId' => $this->userId,
            'rentalId' => $this->rentalId,
            'status' => $this->status,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'studentInfo' => $this->studentInfo,
            'parentInfo' => $this->parentInfo,
            'paymentInfo' => $this->paymentInfo,
            'signatureInfo1' => $this->signatureInfo1,
            'signatureInfo2' => $this->signatureInfo2,
            'prospectiveInfo' => $this->prospectiveInfo,
            'signatures' => $this->signatures,
            'file' => $this->file
        ];
    }
}