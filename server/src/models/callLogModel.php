<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class CallLog {
    public $callType;
    public $status;
    public $createdAt;
    public $user;

    public function __construct(
        $callType, 
        $user, 
        $status = 'Pending'
    ) {
        $this->callType = $callType;
        $this->status = in_array($status, ['Pending', 'In Progress', 'Resolved']) ? $status : 'Pending';
        $this->createdAt = new UTCDateTime();
        $this->user = new ObjectId($user);
    }
}
