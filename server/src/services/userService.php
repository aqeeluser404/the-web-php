<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
// require_once __DIR__ . '/../utils/imageKit.php';
require_once __DIR__ . '/../utils/sendEmail.php';
require_once __DIR__ . '/../utils/LocalFileHelper.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class UserService
{
    private $userCollection;
    private $rentalCollection;
    private $unitCollection;
    private $callLogCollection;
    private $shuttleCollection;
    private $visitorCollection;
    // private $ImageKitService;
    private $emailService;
    private $localFileHelper;

    public function __construct()
    {
        $db = Database::getDb();
        $this->userCollection = $db->User;
        $this->rentalCollection = $db->Rental;
        $this->unitCollection = $db->Unit;
        $this->callLogCollection = $db->calllogs;
        $this->shuttleCollection = $db->Shuttle;
        $this->visitorCollection = $db->Visitor;
        // $this->ImageKitService = new ImageKitService();
        $this->emailService = new EmailService();
        $this->localFileHelper = new LocalFileHelper();
    }

    protected function safeDateFormat($dateValue)
    {
        if ($dateValue instanceof UTCDateTime) {
            return $dateValue->toDateTime()->format('Y-m-d\TH:i:s.vP');
        }
        if (is_string($dateValue)) {
            return $dateValue;
        }
        return null;
    }

    protected function calculateAge($dobUTC)
    {
        if (!$dobUTC)
            return null;

        $dob = $dobUTC instanceof MongoDB\BSON\UTCDateTime
            ? $dobUTC->toDateTime()
            : new DateTime($dobUTC);

        $today = new DateTime();
        return $today->diff($dob)->y; // returns full years
    }

    protected function calculateAndSaveAge($userId)
    {
        $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
        if (!$user) return null;

        $age = isset($user['dateOfBirth']) ? $this->calculateAge($user['dateOfBirth']) : null;
        
        // Update the age in database
        $this->userCollection->updateOne(
            ['_id' => new ObjectId($userId)],
            ['$set' => ['age' => $age]]
        );
        
        return $age;
    }

    protected function assignTenantRights($userId) {
        // Get user's rental IDs
        $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
        if (!$user) return null;

        // If user already has a rightsType that is not Tenant or null, do not override
        if (
            isset($user['rightsType']) 
            && $user['rightsType'] !== null 
            && $user['rightsType'] !== '' 
            && $user['rightsType'] !== 'Tenant'
        ) {
            return $user['rightsType'];
        }

        $rentalIds = isset($user['rentals']) ? $user['rentals']->getArrayCopy() : [];
        if (empty($rentalIds)) {
            // No rentals at all → clear tenant rights
            $this->userCollection->updateOne(
                ['_id' => new ObjectId($userId)],
                ['$unset' => ['rightsType' => 1]]
            );
            return null;
        }

        // Check if there is at least one Active rental
        $activeRental = $this->rentalCollection->findOne([
            '_id'   => ['$in' => $rentalIds],
            'status'=> 'Active'
        ]);

        if ($activeRental) {
            // Assign Tenant rights
            $this->userCollection->updateOne(
                ['_id' => new ObjectId($userId)],
                ['$set' => ['rightsType' => 'Tenant']]
            );
            return 'Tenant';
        }

        // No active rentals → clear tenant rights
        $this->userCollection->updateOne(
            ['_id' => new ObjectId($userId)],
            ['$unset' => ['rightsType' => 1]]
        );
        return null;
    }

    public function handleOtpCheck($user) {
        $otpWindowHours = 24;
        $otpVerifiedAt = $user['loginInfo']['otpVerifiedAt'] ?? null;
        $otpStillValid = false;

        if ($otpVerifiedAt) {
            $lastOtp = $otpVerifiedAt->toDateTime()->getTimestamp();
            if ($lastOtp > (time() - ($otpWindowHours * 3600))) {
                $otpStillValid = true;
            }
        }
        if (!$otpStillValid) {
            $currentOtp   = $user['loginInfo']['currentOtp'] ?? null;
            $otpExpiresAt = $user['loginInfo']['otpExpiresAt'] ?? null;

            if (!$currentOtp || !$otpExpiresAt || $otpExpiresAt->toDateTime()->getTimestamp() < time()) {
                $otp = random_int(100000, 999999);

                $this->userCollection->updateOne(
                    ['_id' => new ObjectId($user['_id'])],
                    ['$set' => [
                        'loginInfo.currentOtp'   => $otp,
                        'loginInfo.otpExpiresAt' => new UTCDateTime((time() + 300) * 1000)
                    ]]
                );

                $this->emailService->sendOtpEmail($user, $otp);
            }
            return false;
        }
        return true; 
    }

    public function generateJwt($user, $expirySeconds = 7200) {
        return JWT::encode(
            [
                '_id' => (string) $user['_id'],
                'userType' => $user['userType'],
                // 'exp' => time() + 86400 // Token expires in 24 hours (86400 seconds)
                // 'exp' => time() + 60 // Token expires in 60 seconds (1 minute)
                'exp' => time() + 7200 // Token expires in 2 hours (7200 seconds)
            ],
            $_ENV['JWT_SECRET'],
            'HS256'
        );
    }

    public function userRegisterService($userDetails)
    {
        try {
            $exists = $this->userCollection->findOne([
                '$or' => [
                    ['username' => $userDetails['username']],
                    ['email' => $userDetails['email']]
                ]
            ]);
            if ($exists) {
                $conflict = [];
                if ($exists['username'] === $userDetails['username'])
                    $conflict[] = 'username';
                if ($exists['email'] === $userDetails['email'])
                    $conflict[] = 'email';
                throw new Exception(implode(' and ', $conflict) . ' already exists');
            }
            ;

            $userType = ($_ENV['CREATE_ADMIN'] === 'true') ? 'admin' : 'user';
            $hashedPassword = password_hash($userDetails['password'], PASSWORD_BCRYPT);

            $userModelData = [
                'firstName' => $userDetails['firstName'],
                'lastName' => $userDetails['lastName'],
                'email' => $userDetails['email'],
                'phone' => $userDetails['phone'],
                'username' => $userDetails['username'],
                'password' => $hashedPassword,
                'userType' => $userType,
                'dateCreated' => new MongoDB\BSON\UTCDateTime(),
                'gender' => $userDetails['gender'],
                'studentInfo' => [
                    'isRegisteredStudent' => $userDetails['studentInfo']['isRegisteredStudent'],
                    'studentNumber' => $userDetails['studentInfo']['studentNumber'],
                    'registeredInstitution' => $userDetails['studentInfo']['registeredInstitution'],
                    'hasBursary' => $userDetails['studentInfo']['hasBursary']
                ],
                'verification' => [
                    'isVerified' => false,
                    'verificationToken' => null,
                    'verificationTokenExpires' => null
                ],
                'documents' => []
            ];

            $insertResult = $this->userCollection->insertOne($userModelData);

            $insertedId = $insertResult->getInsertedId();
            $tokenPayload = [
                'userId' => (string) $insertedId,
                'exp' => time() + 86400
            ];
            $verificationToken = JWT::encode($tokenPayload, $_ENV['JWT_SECRET'], 'HS256');

            // Update user with verification token
            $this->userCollection->updateOne(
                ['_id' => $insertedId],
                [
                    '$set' => [
                        'verification.verificationToken' => $verificationToken,
                        'verification.verificationTokenExpires' => new MongoDB\BSON\UTCDateTime($tokenPayload['exp'] * 1000)
                    ]
                ]
            );

            $newUser = $this->userCollection->findOne(['_id' => $insertedId]);
            if (!$newUser) {
                throw new Exception('Failed to retrieve newly registered user');
            }

            // Send verification email
            $this->emailService->verifyEmail($newUser);

            return true;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function userLoginService($username, $email, $password)
    {
        try {
            $user = $this->userCollection->findOne([
                '$or' => [
                    ['username' => $username],
                    ['email' => $email]
                ]
            ]);
            if (!$user) {
                throw new Exception('User not found');
            }

            if (!password_verify($password, $user['password'])) {
                error_log("Password verification failed for user: " . $user['username']);
                throw new Exception('Invalid password');
            }

            // GENERATE JWT TOKEN
            $token = JWT::encode(
                [
                    '_id' => (string) $user['_id'],
                    'userType' => $user['userType'],
                    'exp' => time() + 86400 // Token expires in 24 hours (86400 seconds)
                    // 'exp' => time() + 60 // Token expires in 60 seconds (1 minute)
                    // 'exp' => time() + 7200 // Token expires in 2 hours (7200 seconds)
                ],
                $_ENV['JWT_SECRET'],
                'HS256'
            );

            return $token;
        } catch (Exception $e) {
            error_log("Error in userLoginService: " . $e->getMessage());
            throw $e;
        }
    }

    public function adminLoginService($username, $email) {
        try {
            $user = $this->userCollection->findOne([
                '$or' => [
                    ['username' => $username],
                    ['email' => $email]
                ]
            ]);
            if (!$user) {
                throw new Exception('User not found');
            }
            $token = JWT::encode(
                [
                    '_id' => (string) $user['_id'],
                    'userType' => $user['userType'],
                    'exp' => time() + 7200 // 2 hours expiry
                ],
                $_ENV['JWT_SECRET'],
                'HS256'
            );
            return $token;
        } catch (Exception $e) {
            error_log("Error in autoLoginService: " . $e->getMessage());
            throw $e;
        }
    }

    // public function userLoginService($username, $email, $password)
    // {
    //     try {
    //         $user = $this->userCollection->findOne([
    //             '$or' => [
    //                 ['username' => $username],
    //                 ['email' => $email]
    //             ]
    //         ]);
    //         if (!$user) {
    //             throw new Exception('User not found');
    //         }

    //         $otpValid = $this->handleOtpCheck($user);
    //         if (!$otpValid) {
    //             return 'otp_required';
    //         }

    //         if (!password_verify($password, $user['password'])) {
    //             error_log("Password verification failed for user: " . $user['username']);
    //             throw new Exception('Invalid password');
    //         }

    //         $token = $this->generateJwt($user);

    //         $refreshToken = $this->generateJwt($user, 604800);

    //         return [
    //             'user' => $user,
    //             'accessToken' => $token,
    //             'refreshToken' => $refreshToken
    //         ];
    //     } catch (Exception $e) {
    //         error_log("Error in userLoginService: " . $e->getMessage());
    //         throw $e;
    //     }
    // }

    
    // public function validateOtpCode($userId, $submittedOtp) {
    //     $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
    //     if (!$user) {
    //         throw new Exception('User not found');
    //     }
    //     $currentOtp   = $user['loginInfo']['currentOtp'] ?? null;
    //     $otpExpiresAt = $user['loginInfo']['otpExpiresAt'] ?? null;

    //     if (!$currentOtp || !$otpExpiresAt) {
    //         throw new Exception('No OTP pending');
    //     }

    //     if ($otpExpiresAt->toDateTime()->getTimestamp() < time()) {
    //         throw new Exception('OTP expired');
    //     }

    //     if ($submittedOtp != $currentOtp) {
    //         throw new Exception('Invalid OTP');
    //     }

    //     $this->userCollection->updateOne(
    //         ['_id' => new ObjectId($user['_id'])],
    //         ['$set' => [
    //             'loginInfo.otpVerifiedAt' => new UTCDateTime(),
    //             'loginInfo.currentOtp'    => null,
    //             'loginInfo.otpExpiresAt'  => null
    //         ]]
    //     );
    //     return true;
    // }

    
    public function userLogoutService($id)
    {
        try {
            $user = $this->userCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$user) {
                throw new Exception('User not found');
            }

            // Update user login status
            $this->userCollection->updateOne(
                ['_id' => new ObjectId($id)],
                [
                    '$set' => [
                        'loginInfo.isLoggedIn' => false,
                        'loginInfo.loginToken' => null
                    ]
                ]
            );

            return 'User logged out successfully';
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findUsersLoggedInService()
    {
        try {
            $users = $this->userCollection->find(['loginInfo.isLoggedIn' => true]);
            return iterator_to_array($users);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findUsersFrequentlyLoggedInService()
    {
        try {
            $users = $this->userCollection->find([], ['sort' => ['loginInfo.loginCount' => -1]]);
            return iterator_to_array($users);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findUserByIdService($id)
    {
        try {
            $user = $this->userCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$user) {
                throw new Exception('User not found');
            }

            // Ensure age is always calculated and saved
            if (!isset($user['age']) && isset($user['dateOfBirth'])) {
                $user['age'] = $this->calculateAndSaveAge($id);
            }

            $this->assignTenantRights($id);
            $user = $this->userCollection->findOne(['_id' => new ObjectId($id)]);
            return $user;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findUserByTokenService(string $token)
    {
        try {
            $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
            $user = $this->userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($decoded->_id)]);

            if (!$user) {
                throw new Exception('User not found');
            }

            // Ensure age is always calculated and saved
            if (!isset($user['age']) && isset($user['dateOfBirth'])) {
                $user['age'] = $this->calculateAndSaveAge($decoded->_id);
            }

            $this->assignTenantRights($decoded->_id);
            $user = $this->userCollection->findOne(['_id' => new ObjectId($decoded->_id)]);
            return $user;
        } catch (Exception $e) {
            error_log("FindUserByTokenService error: " . $e->getMessage());
            throw $e;
        }
    }


    public function createUserService($userDetails)
    {
        try {
            $exists = $this->userCollection->findOne([
                '$or' => [
                    ['username' => $userDetails['username']],
                    ['email' => $userDetails['email']]
                ]
            ]);
            if ($exists) {
                $conflict = [];
                if ($exists['username'] === $userDetails['username'])
                    $conflict[] = 'username';
                if ($exists['email'] === $userDetails['email'])
                    $conflict[] = 'email';
                throw new Exception(implode(' and ', $conflict) . ' already exists');
            }
            ;

            $hashedPassword = password_hash($userDetails['password'], PASSWORD_BCRYPT);

            $userModelData = [
                'firstName' => $userDetails['firstName'],
                'lastName' => $userDetails['lastName'],
                'email' => $userDetails['email'],
                'phone' => $userDetails['phone'],
                'username' => $userDetails['username'],
                'password' => $hashedPassword,
                'userType' => $userDetails['userType'],
                'dateCreated' => new MongoDB\BSON\UTCDateTime(),
                'gender' => $userDetails['gender'],
                'studentInfo' => [
                    'isRegisteredStudent' => $userDetails['studentInfo']['isRegisteredStudent'],
                    'studentNumber' => $userDetails['studentInfo']['studentNumber'],
                    'registeredInstitution' => $userDetails['studentInfo']['registeredInstitution'],
                    'hasBursary' => $userDetails['studentInfo']['hasBursary']
                ],
                'verification' => [
                    'isVerified' => false,
                    'verificationToken' => null,
                    'verificationTokenExpires' => null
                ],
                'documents' => []
            ];

            $this->userCollection->insertOne($userModelData);
            return true;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findAllUsersService(): array
    {
        try {
            $users = $this->userCollection->find();

            $results = [];
            foreach ($users as $doc) {
                $user = [
                    '_id' => (string) $doc['_id'],
                    'firstName' => $doc['firstName'] ?? null,
                    'lastName' => $doc['lastName'] ?? null,
                    'email' => $doc['email'] ?? null,
                    'phone' => $doc['phone'] ?? null,
                    'username' => $doc['username'] ?? null,
                    'userType' => $doc['userType'] ?? null,
                    'gender' => $doc['gender'] ?? null,
                    'dateCreated' => $this->safeDateFormat($doc['dateCreated'] ?? null),
                    'dateOfBirth' => $this->safeDateFormat($doc['dateOfBirth'] ?? null), // new
                    'age' => $this->calculateAge($doc['dateOfBirth'] ?? null),
                    'rightsType' => $doc['rightsType'] ?? null,
                    'hasShuttle' => $doc['hasShuttle'] ?? false,
                    'guardianEmail' => $doc['guardianEmail'] ?? null,
                    'guardianName' => $doc['guardianName'] ?? null
                ];

                $user['studentInfo'] = isset($doc['studentInfo']) ? [
                    'isRegisteredStudent' => $doc['studentInfo']['isRegisteredStudent'] ?? false,
                    'studentNumber' => $doc['studentInfo']['studentNumber'] ?? null,
                    'registeredInstitution' => $doc['studentInfo']['registeredInstitution'] ?? null,
                    'hasBursary' => $doc['studentInfo']['hasBursary'] ?? false
                ] : null;

                $user['verification'] = isset($doc['verification']) ? [
                    'isVerified' => $doc['verification']['isVerified'] ?? false,
                    'verificationToken' => $doc['verification']['verificationToken'] ?? null,
                    'verificationTokenExpires' => $doc['verification']['verificationTokenExpires'] ?? null
                ] : null;
                
                $user['guardianVerification'] = isset($doc['guardianVerification']) ? [
                        'isVerified' => $doc['guardianVerification']['isVerified'] ?? false,
                        'verificationToken' => $doc['guardianVerification']['verificationToken'] ?? null,
                        'verificationTokenExpires' => $doc['guardianVerification']['verificationTokenExpires'] ?? null
                    ] : null;

                $user['loginInfo'] = isset($doc['loginInfo']) ? [
                    'lastLogin' => $this->safeDateFormat($doc['loginInfo']['lastLogin'] ?? null),
                    'isLoggedIn' => $doc['loginInfo']['isLoggedIn'] ?? false,
                    'loginCount' => $doc['loginInfo']['loginCount'] ?? 0
                ] : null;

                $user['documents'] = isset($doc['documents']) ? array_map(function ($document) {
                    return [
                        'documentUrl' => $document['documentUrl'] ?? null,
                        'fileId' => $document['fileId'] ?? null,
                        'uploadDate' => $this->safeDateFormat($document['uploadDate'] ?? null),
                        '_id' => isset($document['_id']) ? (string) $document['_id'] : null
                    ];
                }, $doc['documents']->getArrayCopy()) : [];

                $user['rentals'] = isset($doc['rentals'])
                    ? array_map(fn($id) => (string) $id, $doc['rentals']->getArrayCopy())
                    : [];

                $user['callLogs'] = isset($doc['callLogs'])
                    ? array_map(fn($id) => (string) $id, $doc['callLogs']->getArrayCopy())
                    : [];

                $user['shuttles'] = isset($doc['shuttles'])
                    ? array_map(fn($id) => (string) $id, $doc['shuttles']->getArrayCopy())
                    : [];

                $results[] = $user;
            }

            return $results;
        } catch (Exception $e) {
            error_log('Error in findAllUsersService: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function findUsersByIdsService(array $userIds) {
        try {
            // Remove duplicates and empty values
            $userIds = array_filter(array_unique($userIds));
            if (empty($userIds)) {
                return [];
            }

            $objectIds = array_map(fn($id) => new ObjectId($id), $userIds);
            $users = $this->userCollection->find(['_id' => ['$in' => $objectIds]]);
            
            $results = [];
            foreach ($users as $user) {
                $results[(string) $user['_id']] = [
                    'userId' => (string) $user['_id'],
                    'username' => $user['username'] ?? null,
                    'userFirstName' => $user['firstName'] ?? null,
                    'userLastName' => $user['lastName'] ?? null,
                    'userEmail' => $user['email'] ?? null,
                    'userPhone' => $user['phone'] ?? null,
                    'userVerification' => isset($user['verification']) ? [
                        'isVerified' => $user['verification']['isVerified'] ?? false,
                        'verificationToken' => $user['verification']['verificationToken'] ?? null,
                        'verificationTokenExpires' => $user['verification']['verificationTokenExpires'] ?? null
                    ] : null,
                    'userDocuments' => isset($user['documents']) ? array_map(function ($document) {
                        return [
                            'documentUrl' => $document['documentUrl'] ?? null,
                            'fileId' => $document['fileId'] ?? null,
                            'uploadDate' => $this->safeDateFormat($document['uploadDate'] ?? null),
                            '_id' => isset($document['_id']) ? (string) $document['_id'] : null
                        ];
                    }, $user['documents']->getArrayCopy()) : []
                ];
            }
            return $results;
        } catch (Exception $e) {
            error_log('Error in findUsersByIdsService: ' . $e->getMessage());
            throw $e;
        }
    }


    // public function updateUserService($id, $userDetails)
    // {
    //     try {
    //         // Handle password hashing if present
    //         if (isset($userDetails['password'])) {
    //             $userDetails['password'] = password_hash($userDetails['password'], PASSWORD_BCRYPT);
    //         }

    //         // Handle dateOfBirth conversion
    //         if (isset($userDetails['dateOfBirth']) && !empty($userDetails['dateOfBirth'])) {
    //             $timestamp = strtotime($userDetails['dateOfBirth']);
    //             if ($timestamp !== false) {
    //                 $userDetails['dateOfBirth'] = new UTCDateTime($timestamp * 1000);
    //             } else {
    //                 unset($userDetails['dateOfBirth']);
    //             }
    //         }

    //         $currentUser = $this->userCollection->findOne(['_id' => new ObjectId($id)]);
    //         if (!$currentUser) {
    //             throw new Exception('User not found');
    //         }

    //         // Check if email is being updated
    //         $isEmailUpdated = isset($userDetails['email']) && $userDetails['email'] !== $currentUser['email'];

    //         // Update user details
    //         $this->userCollection->updateOne(
    //             ['_id' => new ObjectId($id)],
    //             ['$set' => $userDetails]
    //         );

    //         // Handle email verification if email was updated
    //         if ($isEmailUpdated) {
    //             $verificationToken = JWT::encode(['userId' => (string) $currentUser['_id']], $_ENV['JWT_SECRET'], 'HS256');
    //             $this->userCollection->updateOne(
    //                 ['_id' => new ObjectId($id)],
    //                 [
    //                     '$set' => [
    //                         'verification.isVerified' => false,
    //                         'verification.verificationToken' => $verificationToken,
    //                         'verification.verificationTokenExpires' => new UTCDateTime(time() * 1000 + 3600000) // 1 hour
    //                     ]
    //                 ]
    //             );
    //         }

    //         // Recalculate and save age if dateOfBirth was updated
    //         if (isset($userDetails['dateOfBirth'])) {
    //             $this->calculateAndSaveAge($id);
    //         }

    //         // Return the fully updated user
    //         return $this->userCollection->findOne(['_id' => new ObjectId($id)]);
    //     } catch (Exception $e) {
    //         throw $e;
    //     }
    // }
    
    public function updateUserService($id, $userDetails)
    {
        try {
            // Handle password hashing if present
            if (isset($userDetails['password'])) {
                $userDetails['password'] = password_hash($userDetails['password'], PASSWORD_BCRYPT);
            }

            // Handle dateOfBirth conversion
            if (isset($userDetails['dateOfBirth']) && !empty($userDetails['dateOfBirth'])) {
                $timestamp = strtotime($userDetails['dateOfBirth']);
                if ($timestamp !== false) {
                    $userDetails['dateOfBirth'] = new UTCDateTime($timestamp * 1000);
                } else {
                    unset($userDetails['dateOfBirth']);
                }
            }

            $currentUser = $this->userCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$currentUser) {
                throw new Exception('User not found');
            }

            // Check if email is being updated
            $isEmailUpdated = isset($userDetails['email']) && $userDetails['email'] !== $currentUser['email'];

            $isGuardianEmailUpdated = isset($userDetails['guardianEmail'])
                && !empty($userDetails['guardianEmail'])
                && $userDetails['guardianEmail'] !== ($currentUser['guardianEmail'] ?? null);

            // Update user details
            $this->userCollection->updateOne(
                ['_id' => new ObjectId($id)],
                ['$set' => $userDetails]
            );

            // Handle email verification if email was updated
            if ($isEmailUpdated) {
                $verificationToken = JWT::encode(['userId' => (string) $currentUser['_id']], $_ENV['JWT_SECRET'], 'HS256');
                $this->userCollection->updateOne(
                    ['_id' => new ObjectId($id)],
                    [
                        '$set' => [
                            'verification.isVerified' => false,
                            'verification.verificationToken' => $verificationToken,
                            'verification.verificationTokenExpires' => new UTCDateTime(time() * 1000 + 3600000) // 1 hour
                        ]
                    ]
                );
            }

            if ($isGuardianEmailUpdated) {
                $guardianVerificationToken = JWT::encode(['userId' => (string) $currentUser['_id'], 'type' => 'guardian'], $_ENV['JWT_SECRET'], 'HS256');
                $this->userCollection->updateOne(
                    ['_id' => new ObjectId($id)],
                    [
                        '$set' => [
                            'guardianVerification.isVerified' => false,
                            'guardianVerification.verificationToken' => $guardianVerificationToken,
                            'guardianVerification.verificationTokenExpires' => new UTCDateTime(time() * 1000 + 3600000) // 1 hour
                        ]
                    ]
                );

                // $updatedUser = $this->userCollection->findOne(['_id' => new ObjectId($id)]);
                // $this->emailService->verifyEmail($updatedUser, 'guardian');
            }

            // Recalculate and save age if dateOfBirth was updated
            if (isset($userDetails['dateOfBirth'])) {
                $this->calculateAndSaveAge($id);
            }

            // Return the fully updated user
            return $this->userCollection->findOne(['_id' => new ObjectId($id)]);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteUserService($id)
    {
        try {
            // Fetch all rentals associated with the user before deleting anything
            $rentals = $this->rentalCollection->find(['user' => new ObjectId($id)])->toArray();

            // Remove rentals from the rental collection and update the corresponding units
            foreach ($rentals as $rental) {
                $this->rentalCollection->deleteOne(['_id' => $rental['_id']]);

                // Remove rental from corresponding unit's rentalHistory
                $this->unitCollection->updateOne(
                    ['_id' => new ObjectId($rental['unit'])],
                    [
                        '$inc' => ['currentOccupants' => -1],
                        '$pull' => ['rentedHistory' => $rental['_id']]
                    ]
                );
            }

            // Remove associated call logs BEFORE deleting the user
            $this->callLogCollection->deleteMany([
                '$or' => [
                    ['user' => new ObjectId($id)],  // Handles ObjectId format
                    ['user' => $id]                 // Handles string format
                ]
            ]);

            // Remove associated shuttles BEFORE deleting the user
            $this->shuttleCollection->deleteMany([
                '$or' => [
                    ['user' => new ObjectId($id)],  // Handles ObjectId format
                    ['user' => $id]                 // Handles string format
                ]
            ]);

            // Remove associated visitors BEFORE deleting the user
            $this->visitorCollection->deleteMany([
                '$or' => [
                    ['user' => new ObjectId($id)],  // Handles ObjectId format
                    ['user' => $id]                 // Handles string format
                ]
            ]);

            // Delete all related user documents from other collections BEFORE deleting the user
            $this->clearAllUserDocsService($id);

            // Now, delete the user safely AFTER all dependencies are handled
            $result = $this->userCollection->deleteOne(['_id' => new ObjectId($id)]);
            if ($result->getDeletedCount() === 0) {
                throw new Exception('User not found');
            }

            return true;
        } catch (Exception $e) {
            throw $e;
        }
    }

    // IMAGEKIT IMPLEMENTATION

    public function uploadUserDocsService(string $userId, array $userDocs)
    {
        try {
            $user = $this->userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
            if (!$user) {
                throw new Exception('User not found');
            }

            if (!empty($userDocs)) {
                $uploadedDocuments = [];
                foreach ($userDocs as $file) {
                    // $uploadedDocuments[] = $this->ImageKitService->uploadDocument($file);
                    // $uploadedDocuments[] = $this->localFileHelper->uploadDocument($file);

                    // Upload the file via helper
                    $uploadResult = $this->localFileHelper->uploadDocument($file);

                    // Merge in docType from UploadedFile (media type, e.g. application/pdf)
                    $uploadedDocuments[] = array_merge(
                        $uploadResult,
                        ['docType' => $_POST['type'] ?? null]
                    );
                }

                $updateResult = $this->userCollection->updateOne(
                    ['_id' => new MongoDB\BSON\ObjectId($userId)],
                    [
                        '$push' => [
                            'documents' => [
                                // '$each' => array_map(function ($doc) {
                                //     return [
                                //         'documentUrl' => $doc['documentUrl'],
                                //         'fileId' => $doc['fileId'],
                                //         'uploadDate' => new MongoDB\BSON\UTCDateTime()
                                //     ];
                                // }, $uploadedDocuments)
                                '$each' => array_map(function ($doc) use ($userDocs) {
                                    return [
                                        'documentUrl' => $doc['documentUrl'],
                                        'fileId'      => $doc['fileId'],
                                        'uploadDate'  => new MongoDB\BSON\UTCDateTime(),
                                        'docType'     => $doc['docType'] ?? null,
                                    ];
                                }, $uploadedDocuments)
                            ]
                        ]
                    ]
                );

                if ($updateResult->getModifiedCount() === 0) {
                    throw new Exception('Failed to update user documents');
                }
            }
            return $this->userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
        } catch (Exception $e) {
            throw $e;
        }
    }


    public function clearAllUserDocsService(string $userId)
    {
        try {
            $user = $this->userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
            if (!$user) {
                throw new Exception('User not found');
            }

            foreach ($user['documents'] as $doc) {
                // $this->ImageKitService->deleteDocument($doc['fileId']);
                $this->localFileHelper->deleteDocument($doc['fileId']);
            }

            $updateResult = $this->userCollection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($userId)],
                ['$set' => ['documents' => []]]
            );
            return $this->userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
        } catch (Exception $e) {
            throw $e;
        }
    }


    public function removeUserDocService(string $userId, string $fileId)
    {
        try {
            $user = $this->userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
            if (!$user) {
                throw new Exception('User not found');
            }

            $documentExists = false;
            foreach ($user['documents'] as $doc) {
                if ($doc['fileId'] === $fileId) {
                    $documentExists = true;
                    break;
                }
            }

            if (!$documentExists) {
                throw new Exception('Document not found');
            }

            // $this->ImageKitService->deleteDocument($fileId);
            $this->localFileHelper->deleteDocument($fileId);

            $updateResult = $this->userCollection->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($userId)],
                ['$pull' => ['documents' => ['fileId' => $fileId]]]
            );
            return $this->userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
        } catch (Exception $e) {
            throw $e;
        }
    }
}

