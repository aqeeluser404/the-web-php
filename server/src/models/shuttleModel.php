<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class Shuttle {
    public $shuttleNumber;
    public $pickupLocation;
    public $dropoffLocation;
    public $bookingTimeslot;
    public $createdAt;
    public $status;
    public $user;
    public $driverNotes;

    public function __construct(
        $shuttleNumber,
        $pickupLocation,
        $dropoffLocation,
        ?string $bookingTimeslot = null,
        ?string $createdAt = null,
        $status = 'Pending',
        $user,
        $driverNotes = []
    ) {
        $this->shuttleNumber = $shuttleNumber;
        $this->pickupLocation = $pickupLocation;
        $this->dropoffLocation = $dropoffLocation;
        $this->bookingTimeslot = $bookingTimeslot ? new UTCDateTime(strtotime($bookingTimeslot) * 1000) : null;
        $this->createdAt = $createdAt ? new UTCDateTime(strtotime($createdAt) * 1000) : null;
        $this->status = in_array($status, ['Pending', 'Picked Up', 'Dropped Off']) ? $status : 'Pending';
        $this->user = new ObjectId($user);

        $this->driverNotes = array_merge([
            'notes' => null,
            'driverNotesDate' => new UTCDateTime()
        ], $driverNotes);
    }
}