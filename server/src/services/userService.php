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

class UserService {
    private $userCollection;
    // private $ImageKitService;
    private $emailService;
    private $localFileHelper;

    public function __construct() {
        $db = Database::getDb();
        $this->userCollection = $db->User;
        // $this->ImageKitService = new ImageKitService();
        $this->emailService = new EmailService();
        $this->localFileHelper = new LocalFileHelper(); 
    }

    protected function safeDateFormat($dateValue) {
        if ($dateValue instanceof UTCDateTime) {
            return $dateValue->toDateTime()->format('Y-m-d\TH:i:s.vP');
        }
        if (is_string($dateValue)) {
            return $dateValue;
        }
        return null; 
    }

    public function userRegisterService($userDetails) {
        try {
            $exists = $this->userCollection->findOne([
                '$or' => [
                    ['username' => $userDetails['username']],
                    ['email' => $userDetails['email']]
                ]
            ]);
            if ($exists) {
                $conflict = [];
                if ($exists['username'] === $userDetails['username']) $conflict[] = 'username';
                if ($exists['email'] === $userDetails['email']) $conflict[] = 'email';
                throw new Exception(implode(' and ', $conflict) . ' already exists');
            };

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
                    'registeredInstitution' => $userDetails['studentInfo']['registeredInstitution']
                ],
                'verification' => [
                    'isVerified' => false,
                    'verificationToken' => JWT::encode(['userId' => (string) new ObjectId()], $_ENV['JWT_SECRET'], 'HS256'),
                    'verificationTokenExpires' => new MongoDB\BSON\UTCDateTime(time() * 1000 + 86400000) // 24 hours
                ]
            ];

            $this->userCollection->insertOne($userModelData);

            // SEND VERIFICATION EMAIL
            $this->emailService->verifyEmail($userModelData);

            return true;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function userLoginService($username, $email, $password) {
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
                ['_id' => (string) $user['_id'], 'userType' => $user['userType']],
                $_ENV['JWT_SECRET'], 
                'HS256'
            );
            return $token;
        } catch (Exception $e) {
            error_log("Error in userLoginService: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function userLogoutService($id) {
        try {
            $user = $this->userCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$user) {
                throw new Exception('User not found');
            }

            // Update user login status
            $this->userCollection->updateOne(
                ['_id' => new ObjectId($id)],
                ['$set' => [
                    'loginInfo.isLoggedIn' => false,
                    'loginInfo.loginToken' => null
                ]]
            );

            return 'User logged out successfully';
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findUsersLoggedInService() {
        try {
            $users = $this->userCollection->find(['loginInfo.isLoggedIn' => true]);
            return iterator_to_array($users);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findUsersFrequentlyLoggedInService() {
        try {
            $users = $this->userCollection->find([], ['sort' => ['loginInfo.loginCount' => -1]]);
            return iterator_to_array($users);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findUserByIdService($id) {
        try {
            $user = $this->userCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$user) {
                throw new Exception('User not found');
            }
            return $user;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findUserByTokenService(string $token) {
        try {
            $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
            
            $user = $this->userCollection->findOne([
                '_id' => new MongoDB\BSON\ObjectId($decoded->_id)
            ]);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            return $user;
        } catch (Exception $e) {
            error_log("FindUserByTokenService error: " . $e->getMessage());
            throw $e;
        }
    }

    public function createUserService($userDetails) {
        try {
            $existingUser = $this->userCollection->findOne(['username' => $userDetails['username']]);
            if ($existingUser) {
                throw new Exception('Username already exists');
            }

            $hashedPassword = password_hash($userDetails['password'], PASSWORD_BCRYPT);

            $userModelData = [
                'firstName' => $userDetails['firstName'],
                'lastName' => $userDetails['lastName'],
                'email' => $userDetails['email'],
                'phone' => $userDetails['phone'],
                'username' => $userDetails['username'],
                'password' => $hashedPassword,
                'userType' => $userDetails['userType'],
                'dateCreated' => new MongoDB\BSON\UTCDateTime()
            ];

            $this->userCollection->insertOne($userModelData);
            return true;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function findAllUsersService(): array {
        try {
            $users = $this->userCollection->find();
            
            $results = [];
            foreach ($users as $doc) {
                $user = [
                    '_id' => (string)$doc['_id'],
                    'firstName' => $doc['firstName'] ?? null,
                    'lastName' => $doc['lastName'] ?? null,
                    'email' => $doc['email'] ?? null,
                    'phone' => $doc['phone'] ?? null,
                    'username' => $doc['username'] ?? null,
                    'userType' => $doc['userType'] ?? null,
                    'gender' => $doc['gender'] ?? null,
                    'dateCreated' => $this->safeDateFormat($doc['dateCreated'] ?? null)
                ];
    
                $user['studentInfo'] = isset($doc['studentInfo']) ? [
                    'isRegisteredStudent' => $doc['studentInfo']['isRegisteredStudent'] ?? false,
                    'studentNumber' => $doc['studentInfo']['studentNumber'] ?? null,
                    'registeredInstitution' => $doc['studentInfo']['registeredInstitution'] ?? null
                ] : null;
    
                $user['verification'] = isset($doc['verification']) ? [
                    'isVerified' => $doc['verification']['isVerified'] ?? false,
                    'verificationToken' => $doc['verification']['verificationToken'] ?? null,
                    'verificationTokenExpires' => $doc['verification']['verificationTokenExpires'] ?? null
                ] : null;
    
                $user['loginInfo'] = isset($doc['loginInfo']) ? [
                    'lastLogin' => $this->safeDateFormat($doc['loginInfo']['lastLogin'] ?? null),
                    'isLoggedIn' => $doc['loginInfo']['isLoggedIn'] ?? false,
                    'loginCount' => $doc['loginInfo']['loginCount'] ?? 0
                ] : null;
    
                $user['documents'] = isset($doc['documents']) ? array_map(function($document) {
                    return [
                        'documentUrl' => $document['documentUrl'] ?? null,
                        'fileId' => $document['fileId'] ?? null,
                        'uploadDate' => $this->safeDateFormat($document['uploadDate'] ?? null),
                        '_id' => isset($document['_id']) ? (string)$document['_id'] : null
                    ];
                }, $doc['documents']->getArrayCopy()) : [];
    
                $user['rentals'] = isset($doc['rentals']) 
                    ? array_map(fn($id) => (string)$id, $doc['rentals']->getArrayCopy())
                    : [];
    
                $user['callLogs'] = isset($doc['callLogs']) 
                    ? array_map(fn($id) => (string)$id, $doc['callLogs']->getArrayCopy())
                    : [];
    
                $results[] = $user;
            }
            
            return $results;
        } catch (Exception $e) {
            error_log('Error in findAllUsersService: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateUserService($id, $userDetails) {
        try {
            if (isset($userDetails['password'])) {
                $userDetails['password'] = password_hash($userDetails['password'], PASSWORD_BCRYPT);
            }

            $currentUser = $this->userCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$currentUser) {
                throw new Exception('User not found');
            }

            $isEmailUpdated = isset($userDetails['email']) && $userDetails['email'] !== $currentUser['email'];

            $this->userCollection->updateOne(
                ['_id' => new ObjectId($id)],
                ['$set' => $userDetails]
            );

            // CREATE EMAIL VERIFICATION TOKEN IF EMAIL IS UPDATED
            if ($isEmailUpdated) {
                $verificationToken = JWT::encode(['userId' => (string) $currentUser['_id']], $_ENV['JWT_SECRET'], 'HS256');
                $this->userCollection->updateOne(
                    ['_id' => new ObjectId($id)],
                    ['$set' => [
                        'verification.isVerified' => false,
                        'verification.verificationToken' => $verificationToken,
                        'verification.verificationTokenExpires' => new UTCDateTime(time() * 1000 + 3600000) // 1 hour
                    ]]
                );
            }
            return $this->userCollection->findOne(['_id' => new ObjectId($id)]);
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function deleteUserService($id) {
        try {
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

    public function uploadUserDocsService(string $userId, array $userDocs) {
        try {
            $user = $this->userCollection->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);
            if (!$user) {
                throw new Exception('User not found');
            }

            if (!empty($userDocs)) {
                $uploadedDocuments = [];
                foreach ($userDocs as $file) {
                    // $uploadedDocuments[] = $this->ImageKitService->uploadDocument($file);
                    $uploadedDocuments[] = $this->localFileHelper->uploadDocument($file);
                }

                $updateResult = $this->userCollection->updateOne(
                    ['_id' => new MongoDB\BSON\ObjectId($userId)],
                    ['$push' => ['documents' => [
                        '$each' => array_map(function($doc) {
                            return [
                                'documentUrl' => $doc['documentUrl'],
                                'fileId' => $doc['fileId'],
                                'uploadDate' => new MongoDB\BSON\UTCDateTime()
                            ];
                        }, $uploadedDocuments)
                    ]]]
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


    public function clearAllUserDocsService(string $userId) {
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


    public function removeUserDocService(string $userId, string $fileId) {
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

