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
    public $age;
    public $dateOfBirth;
    public $studentInfo;
    public $verification;
    public $forgotPassword;
    public $loginInfo;
    public $rentals;
    public $callLogs;
    public $documents;
    public $rightsType;
    public $shuttles;
    public $hasShuttle;
    
    public $guardianEmail;
    public $guardianVerification;
    public $guardianName; 

    public function __construct(
        $firstName,
        $lastName,
        $email,
        $phone,
        $username,
        $password,
        $userType,
        $gender = null,
        $age = null,
        $dateOfBirth = null, 
        $studentInfo = [],
        $verification = [],
        $forgotPassword = [],
        $loginInfo = [],
        $rentals = [],
        $callLogs = [],
        $documents = [],
        $shuttles = [],
        $rightsType = null,
        $hasShuttle = false,
        
        $guardianEmail = null, 
        $guardianVerification = [],
        $guardianName = null  
    ) {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
        $this->phone = $phone;
        $this->username = $username;
        $this->password = $password;
        $this->userType = $userType;
        $this->gender = $gender;
        $this->age = $age; 
        $this->dateOfBirth = $dateOfBirth;
        $this->dateCreated = new UTCDateTime();
        $this->rightsType = $rightsType; 
        $this->hasShuttle = $hasShuttle;
        
        $this->guardianEmail = $guardianEmail;
        $this->guardianName = $guardianName;

        // Nested Fields ----------------------------------------------------------------
        
        // Student Info
        $this->studentInfo = array_merge([
            'isRegisteredStudent' => false,
            'studentNumber' => null,
            'registeredInstitution' => null,
            'hasBursary' => false
        ], $studentInfo);

        // Verification
        $this->verification = array_merge([
            'isVerified' => false,
            'verificationToken' => null,
            'verificationTokenExpires' => null
        ], $verification);
        
        $this->guardianVerification = array_merge([
            'isVerified' => false,
            'verificationToken' => null,
            'verificationTokenExpires' => null
        ], $guardianVerification);

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
        $this->shuttles = array_map(fn($id) => new ObjectId($id), $shuttles);

        // Documents (uploads)
        $this->documents = array_map(function($doc) {
            return array_merge([
                'documentUrl' => null,
                'fileId' => null,
                'uploadDate' => new UTCDateTime(),
                'docType'     => null, 
            ], $doc);
        }, $documents);
    }
}