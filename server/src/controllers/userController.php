<?php
require_once __DIR__ . '/../services/userService.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use Dotenv\Dotenv;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class UserController {
    private $userCollection;
    private $userService;

    public function __construct() {
        $db = Database::getDb();
        $this->userCollection = $db->User;
        $this->userService = new UserService();
    }

    protected function respond(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    protected function respondText(Response $response, string $text, int $status = 200): Response {
        $response->getBody()->write($text);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/plain');
    }

    public function userRegisterController(Request $req, Response $res): Response {
        try {
            $userDetails = $req->getParsedBody();
            $this->userService->userRegisterService($userDetails);

            return $this->respond($res, ['message' => 'User registered'], 201);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function userLoginController(Request $req, Response $res): Response {
        try {
            $body = $req->getParsedBody();
    
            $username = $body['username'] ?? null;
            $email = $body['email'] ?? null;
            $password = $body['password'] ?? null;
    
            if (!$username && !$email) {
                throw new Exception("Username or email must be provided");
            }
    
            $token = $this->userService->userLoginService($username, $email, $password);
            $user = $this->userService->findUserByTokenService($token);
    
            $isProduction = ($_ENV['NODE_ENV'] ?? 'development') === 'production';
            if ($user['loginInfo']['isLoggedIn'] && $user['loginInfo']['loginToken'] !== $token) {
                $removeLoginData = ['loginInfo.isLoggedIn' => false, 'loginInfo.loginToken' => null];
                $this->userCollection->updateOne(
                    ['_id' => new ObjectId($user['_id'])],
                    ['$set' => $removeLoginData]
                );
                setcookie('token', '', [
                    'expires' => time() - 3600,            // Expire immediately
                    'path' => '/',
                    'secure' => $isProduction,
                    'httponly' => true,
                    'samesite'  => 'None'
                ]);
            }

            // Update user login status
            $updateData = [
                'loginInfo.lastLogin' => new UTCDateTime(),
                'loginInfo.isLoggedIn' => true,
                'loginInfo.loginCount' => $user['loginInfo']['loginCount'] + 1,
                'loginInfo.loginToken' => $token
            ];
            $this->userCollection->updateOne(
                ['_id' => new ObjectId($user['_id'])],
                ['$set' => $updateData]
            );

            $maxAge = 24 * 60 * 60;                     // 1 day till token expires
            setcookie('token', $token, [
                'expires'   => time() + $maxAge,
                'path'      => '/',
                'secure'    => $isProduction,
                'httponly'  => true,
                'samesite'  => 'None'
            ]);

            return $this->respondText($res, $token);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function userLogoutController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $this->userService->userLogoutService($id);
    
            $isProduction = $_ENV['NODE_ENV'] === 'production';
    
            setcookie('token', '', [
                'expires' => time() - 3600,            // Expire immediately
                'path' => '/',
                'secure' => $isProduction,
                'httponly' => true,
                'samesite' => 'None'
            ]);
            return $this->respond($res,  ['message' => 'User logged out successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function findUsersLoggedInController($req, $res) {
        try {
            $users = $this->userService->findUsersLoggedInService();
            return $this->respond($res,  $users, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function findUsersFrequentlyLoggedInController($req, $res) {
        try {
            $users = $this->userService->findUsersFrequentlyLoggedInService();
            return $this->respond($res,  $users, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function findUserByIdController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $user = $this->userService->findUserByIdService($id);
            return $this->respond($res,  $user, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findUserByTokenController(ServerRequestInterface $req, ResponseInterface $res): ResponseInterface
    {
        try {
            $token = $req->getCookieParams()['token'] ?? null;
            
            if (!$token && preg_match('/Bearer\s+(.*)$/i', $req->getHeaderLine('Authorization'), $matches)) {
                $token = $matches[1];
            }
    
            if (!$token) {
                return $this->respond($res,  ['message' => 'Access Denied'], 401);
            }
    
            $user = $this->userService->findUserByTokenService($token);

            return $this->respond($res,  $user, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createUserController($req, $res) {
        try {
            $this->userService->createUserService($req->getParsedBody());
            return $this->respond($res,  ['message' => 'User created successfully'], 201);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function findAllUsersController($req, $res) {
        try {
            $users = $this->userService->findAllUsersService();
            return $this->respond($res,  $users, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function updateUserController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $userDetails = $req->getParsedBody();

            $this->userService->updateUserService($id, $userDetails);
            return $this->respond($res,  ['message' => 'User updated successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function deleteUserController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $this->userService->deleteUserService($id);
            return $this->respond($res,  ['message' => 'User deleted successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function uploadUserDocsController($req, $res) {
        try {
            $userId = $req->getAttribute('id');
            $files = $req->getUploadedFiles();
            $result = $this->userService->uploadUserDocsService($userId, $files);
            return $this->respond($res,  $result, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function clearAllUserDocsController($req, $res) {
        try {
            $userId = $req->getAttribute('id');
            $result = $this->userService->clearAllUserDocsService($userId);
            return $this->respond($res,  $result, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function removeUserDocController($req, $res) {
        try {
            $userId = $req->getAttribute('id');
            $fileId = $req->getAttribute('doc');
            $result = $this->userService->removeUserDocService($userId, $fileId);
            return $this->respond($res,  $result, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }
}