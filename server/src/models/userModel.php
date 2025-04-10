<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class User {
    public $firstName;
    public $lastName;
    public $email;
    public $phone;
    public $username;
    public $password;
    public $userType;
    public $dateCreated;
    public $gender;
    public $studentInfo;
    public $verification;
    public $forgotPassword;
    public $loginInfo;
    public $rentals;
    public $callLogs;
    public $documents;

    public function __construct(
        $firstName,
        $lastName,
        $email,
        $phone,
        $username,
        $password,
        $userType,
        $gender = null,
        $studentInfo = [],
        $verification = [],
        $forgotPassword = [],
        $loginInfo = [],
        $rentals = [],
        $callLogs = [],
        $documents = []
    ) {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
        $this->phone = $phone;
        $this->username = $username;
        $this->password = $password;
        $this->userType = $userType;
        $this->gender = $gender;
        $this->dateCreated = new UTCDateTime();

        // Nested Fields ----------------------------------------------------------------
        
        // Student Info
        $this->studentInfo = array_merge([
            'isRegisteredStudent' => false,
            'studentNumber' => null,
            'registeredInstitution' => null
        ], $studentInfo);

        // Verification
        $this->verification = array_merge([
            'isVerified' => false,
            'verificationToken' => null,
            'verificationTokenExpires' => null
        ], $verification);

        // Forgot Password
        $this->forgotPassword = array_merge([
            'resetPasswordToken' => null,
            'resetPasswordExpires' => null
        ], $forgotPassword);

        // Login Info
        $this->loginInfo = array_merge([
            'lastLogin' => null,
            'isLoggedIn' => false,
            'loginCount' => 0,
            'loginToken' => null
        ], $loginInfo);

        // FOREIGN KEYS
        $this->rentals = array_map(fn($id) => new ObjectId($id), $rentals);
        $this->callLogs = array_map(fn($id) => new ObjectId($id), $callLogs);

        // Documents (uploads)
        $this->documents = array_map(function($doc) {
            return array_merge([
                'documentUrl' => null,
                'fileId' => null,
                'uploadDate' => new UTCDateTime()
            ], $doc);
        }, $documents);
    }



    // // Hide sensitive fields when serializing
    // public function jsonSerialize(): array {
    //     return $this->toSafeArray();
    // }

    // public function toSafeArray(): array {
    //     $data = get_object_vars($this);
        
    //     // Always remove sensitive fields
    //     unset($data['password']);
        
    //     // Clean nested fields
    //     if (isset($data['loginInfo']['loginToken'])) {
    //         unset($data['loginInfo']['loginToken']);
    //     }
        
    //     if (isset($data['forgotPassword'])) {
    //         unset($data['forgotPassword']['resetPasswordToken']);
    //     }
        
    //     // Convert MongoDB types
    //     $data['dateCreated'] = $this->dateCreated->toDateTime()->format('c');
        
    //     return $data;
    // }
}