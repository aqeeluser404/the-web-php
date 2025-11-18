<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../utils/sendEmail.php';

use Dotenv\Dotenv;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class SendEmailCOntroller {
    private $emailService;
    private $userCollection;
    private $unitCollection;
    private $rentalCollection;
    private $callLogCollection;

    public function __construct() {
        $db = Database::getDb();
        $this->callLogCollection = $db->calllogs;
        $this->userCollection = $db->User;
        $this->unitCollection = $db->Unit;
        $this->rentalCollection = $db->Rental;
        $this->emailService = new EmailService();
    }

    protected function respond(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    // -----------------------------------------------------------------------------------------------------------------> WORKING
    public function verifyEmailController($req, $res) {
        try {
            // Get token from query 
            $queryParams = $req->getQueryParams();
            $token = $queryParams['token'] ?? null;
            if (!$token) {
                return $this->respond($res, ['error' => 'Token is required.'], 400);
            }
            // decode token
            $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
            $userId = new ObjectId($decoded->userId);
            // find user
            $user = $this->userCollection->findOne([
                '_id' => $userId,
                'verification.verificationToken' => $token
            ]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 404);
            }
            // Check token expiration
            $expirationTime = $user['verification']['verificationTokenExpires'] ?? null;
            if ($expirationTime && $expirationTime instanceof UTCDateTime) {
                if ($expirationTime->toDateTime()->getTimestamp() < time()) {
                    return $this->respond($res, ['error' => 'Token has expired.'], 400);
                }
            }
            // Update user verification status
            $updateResult = $this->userCollection->updateOne(
                ['_id' => $userId],
                ['$set' => [
                    'verification.isVerified' => true,
                    'verification.verificationToken' => null,
                    'verification.verificationTokenExpires' => null
                ]]
            );
            if ($updateResult->getModifiedCount() === 0) {
                throw new Exception('Failed to update user verification status');
            }
            return $this->respond($res, ['message' => 'Email verified successfully!']);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // -----------------------------------------------------------------------------------------------------------------> WORKING
    public function resendVerificationEmailController($req, $res) {
        try {
            $email = $req->getParsedBody()['email'] ?? null;
            if (!$email) {
                return $this->respond($res, ['error' => 'Email is required.'], 400);
            }
            // Find user by email
            $user = $this->userCollection->findOne(['email' => $email]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 404);
            }
            if (($user['verification']['isVerified'] ?? false) === true) {
                return $this->respond($res, ['error' => 'Email is already verified.'], 400);
            }
            // Generate new verification token (24 hour expiration)
            $tokenPayload = [
                'userId' => (string)$user['_id'],
                'exp' => time() + 86400 // 24 hours
            ];
            $verificationToken = JWT::encode($tokenPayload, $_ENV['JWT_SECRET'], 'HS256');
            // Update user document with new token
            $updateResult = $this->userCollection->updateOne(
                ['_id' => $user['_id']],
                ['$set' => [
                    'verification.verificationToken' => $verificationToken,
                    'verification.verificationTokenExpires' => new UTCDateTime(($tokenPayload['exp']) * 1000)
                ]]
            );
            if ($updateResult->getModifiedCount() === 0) {
                throw new Exception('Failed to update verification token');
            }
            // Update the user array with new verification data
            // $user['verification']['verificationToken'] = $verificationToken;
            // $user['verification']['verificationTokenExpires'] = new UTCDateTime(($tokenPayload['exp']) * 1000);
    
            $userForEmail = $this->userCollection->findOne(['_id' => new ObjectId($user['_id'])]);

            $this->emailService->verifyEmail( $userForEmail);
            return $this->respond($res, ['message' => 'Verification email resent.']);
        } catch (Exception $e) {
            error_log('Error resending verification email: ' . $e->getMessage());
            return $this->respond($res, [
                'error' => 'Error resending verification email.'
            ], 500);
        }
    }

    // -----------------------------------------------------------------------------------------------------------------> WORKING
    public function forgotPasswordController($req, $res) {
        try {
            $email = $req->getParsedBody()['email'] ?? null;
            if (!$email) {
                return $this->respond($res, ['error' => 'Email is required.'], 400);
            }
            // Find user by email
            $user = $this->userCollection->findOne(['email' => $email]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 404);
            }
            // Generate reset token (hex string)
            $resetToken = bin2hex(random_bytes(20));
            $expiresAt = new UTCDateTime((time() + 3600) * 1000); // 1 hour from now
            
            // Update user document
            $updateResult = $this->userCollection->updateOne(
                ['_id' => $user['_id']],
                ['$set' => [
                    'forgotPassword.resetPasswordToken' => $resetToken,
                    'forgotPassword.resetPasswordExpires' => $expiresAt
                ]]
            );

            if ($updateResult->getModifiedCount() === 0) {
                throw new Exception('Failed to update password reset token');
            }
    
            $userForEmail = $this->userCollection->findOne(['_id' => new ObjectId($user['_id'])]);

            // Send reset email with full user object
            $this->emailService->sendResetEmail($userForEmail, $resetToken);
            return $this->respond($res, ['message' => 'Password reset email sent.']);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // -----------------------------------------------------------------------------------------------------------------> WORKING
    public function resetPasswordController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $token = $body['token'] ?? null;
            $password = $body['password'] ?? null;

            if (!$token || !$password) {
                return $this->respond($res, ['error' => 'Token and password are required.'], 400);
            }
            // Find user with valid token
            $user = $this->userCollection->findOne([
                'forgotPassword.resetPasswordToken' => $token,
                'forgotPassword.resetPasswordExpires' => ['$gt' => new UTCDateTime(time() * 1000)]
            ]);
            if (!$user) {
                return $this->respond($res, ['error' => 'Password reset token is invalid or has expired.'], 400);
            }

            // Hash new password
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

            // Update user document
            $updateResult = $this->userCollection->updateOne(
                ['_id' => $user['_id']],
                ['$set' => [
                    'password' => $hashedPassword,
                    'forgotPassword.resetPasswordToken' => null,
                    'forgotPassword.resetPasswordExpires' => null
                ]]
            );
            if ($updateResult->getModifiedCount() === 0) {
                throw new Exception('Failed to update password');
            }
            return $this->respond($res, ['message' => 'Password has been reset.']);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ---> CANT CHECK YET
    public function getInContactController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $userContact = $body['userContact'] ?? null;
            $message = $body['message'] ?? null;

            // $userForEmail = $this->userCollection->findOne(['email' => $userContact['email']]);
            // if (!$userForEmail ) {
            //     return $this->respond($res, ['error' => 'User not found.'], 400);
            // }

            $this->emailService->getInContactEmail( $userContact, $message);
            return $this->respond($res, ['message' => 'Message sent successfully.'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function rentalApplicationEmailController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $userId = $body['userId'] ?? null;

            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 400);
            }
            $this->emailService->rentalApplicationEmail( $user);
            return $this->respond($res, ['message' => 'Message sent successfully.'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function rentalApplicationToUserEmailController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $userId = $body['userId'] ?? null;

            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 400);
            }
            $this->emailService->rentalApplicationToUserEmail( $user);
            return $this->respond($res, ['message' => 'Message sent successfully.'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function documentUploadToUserEmailController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $userId = $body['userId'] ?? null;

            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 400);
            }

            $this->emailService->documentUploadToUserEmail( $user);
            return $this->respond($res, ['message' => 'Message sent successfully.'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function documentUploadEmailController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $userId = $body['userId'] ?? null;

            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 400);
            }

            $this->emailService->documentUploadEmail( $user);
            return $this->respond($res, ['message' => 'Message sent successfully.'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function rentalNotifcationController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $userId = $body['userId'] ?? null;
            $unitId = $body['unitId'] ?? null;
            $rentalId = $body['rentalId'] ?? null;

            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 400);
            }
            $unit = $this->unitCollection->findOne(['_id' => new ObjectId($unitId)]);
            if (!$unit) {
                return $this->respond($res, ['error' => 'Unit not found.'], 400);
            }
            $rental = $this->rentalCollection->findOne(['_id' => new ObjectId($rentalId)]);
            if (!$rental) {
                return $this->respond($res, ['error' => 'Unit not found.'], 400);
            }
            $this->emailService->rentalNotificationEmail( $user, $unit, $rental);
            return $this->respond($res, ['message' => 'Message sent successfully.'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function sendRentalActionReminderController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $userId = $body['userId'] ?? null;
            $message = $body['message'] ?? null;

            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 400);
            }

            $this->emailService->sendRentalActionReminderEmail($user, $message);
            return $this->respond($res, ['message' => 'Reminder email sent successfully.'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function sendRentalRejectionController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $userId = $body['userId'] ?? null;
            $message = $body['message'] ?? null;

            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 400);
            }
            $this->emailService->sendRentalRejectionEmail( $user, $message);
            return $this->respond($res, ['message' => 'Message sent successfully.'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // -----------------------------------------------------------------------------------------------------------------> WORKING
    public function sendExtendedDateEmailController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $message = $req->getParsedBody();

            $user = $this->userCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 400);
            }
            $this->emailService->sendExtendedDateEmail( $user, $message);
            return $this->respond($res, ['message' => 'Message sent successfully.'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function sendVendorController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $userId = $body['userId'] ?? null;
            $callLogId = $body['callLogId'] ?? null;

            $user = $this->userCollection->findOne(['_id' => new ObjectId($userId)]);
            if (!$user) {
                return $this->respond($res, ['error' => 'User not found.'], 400);
            }
            $callLog = $this->callLogCollection->findOne(['_id' => new ObjectId($callLogId)]);
            if (!$callLog) {
                return $this->respond($res, ['error' => 'Call Log not found.'], 400);
            }


            $this->emailService->sendVendorEmail( $user, $callLog);
            return $this->respond($res, ['message' => 'Message sent successfully.'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
