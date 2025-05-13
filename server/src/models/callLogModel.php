<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class CallLog {
    public $logNumber;
    public $callType;
    public $status;
    public $createdAt;
    public $closedAt;
    public $user;
    public $vendorInfo;  

    public function __construct(
        $logNumber,
        $callType, 
        $user, 
        $status = 'Pending',
        ?string $createdAt = null,
        ?string $closedAt = null,
        $vendorInfo = []
    ) {
        $this->logNumber = $logNumber;
        $this->callType = $callType;
        $this->status = in_array($status, ['Pending', 'In Progress', 'Resolved']) ? $status : 'Pending';
        $this->createdAt = $createdAt ? new UTCDateTime(strtotime($createdAt) * 1000) : null;
        $this->closedAt = $closedAt ? new UTCDateTime(strtotime($closedAt) * 1000) : null;
        $this->user = new ObjectId($user);
        
        $this->vendorInfo = array_merge([
            'vendorType' => null,
            'vendorContact' => null,
            'vendorAssignedDate' => new UTCDateTime()
        ], $vendorInfo);        
    }
}