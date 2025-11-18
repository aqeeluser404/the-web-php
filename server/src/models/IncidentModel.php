<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\UTCDateTime;

class Incident {
    public $logNumber;
    public $firstName;
    public $lastName;
    public $email;
    public $phone;
    public $incidentNature;
    public $createdAt;
    public $location;
    public $description;

    public function __construct(
        $logNumber,
        $firstName,
        $lastName,
        $email,
        $phone,
        $incidentNature,
        ?string $createdAt = null,
        $location,
        $description
    ) {
        $this->logNumber = $logNumber;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
        $this->phone = $phone;
        $this->incidentNature = $incidentNature;
        $this->createdAt = $createdAt ? new UTCDateTime(strtotime($createdAt) * 1000) : null;
        $this->location = $location;
        $this->description = $description;
    }
}