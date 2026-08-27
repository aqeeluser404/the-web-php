<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class Visitor {
    public $visitorNumber;
    public $firstName;
    public $lastName;
    public $bookingTimeslot;
    public $entryTimeslot;
    public $exitTimeslot;
    public $status;
    public $user;

    public function __construct(
        $visitorNumber,
        $firstName,
        $lastName,
        ?string $bookingTimeslot = null,
        ?string $entryTimeslot = null,
        ?string $exitTimeslot = null,
        $status = 'Pending',
        $user
    ) {
        $this->visitorNumber = $visitorNumber;
        $this->firstName = $firstName;
        $this->lastName = $lastName;

        $this->bookingTimeslot = $bookingTimeslot ? new UTCDateTime(strtotime($bookingTimeslot) * 1000) : null;
        $this->entryTimeslot = $entryTimeslot ? new UTCDateTime(strtotime($entryTimeslot) * 1000) : null;
        $this->exitTimeslot = $exitTimeslot ? new UTCDateTime(strtotime($exitTimeslot) * 1000) : null;

        $this->status = in_array($status, ['Pending', 'Visiting', 'Completed']) ? $status : 'Pending';
        $this->user = new ObjectId($user);
    }

    public function toArray(): array {
        return [
            'visitorNumber' => $this->visitorNumber,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'bookingTimeslot' => $this->bookingTimeslot,
            'entryTimeslot' => $this->entryTimeslot,
            'exitTimeslot' => $this->exitTimeslot,
            'status' => $this->status,
            'user' => $this->user
        ];
    }
}