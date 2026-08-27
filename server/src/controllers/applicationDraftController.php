<?php
require_once __DIR__ . '/../services/applicationDraftService.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use Dotenv\Dotenv;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\UploadedFile;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class ApplicationDraftController {
    private $applicationDraftService;

    public function __construct() {
        $this->applicationDraftService = new ApplicationDraftService();
    }

    protected function respond(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public function saveApplicationDraftController($req, $res) {
        try {
            $body = $req->getParsedBody();
            $result = $this->applicationDraftService->saveApplicationDraftService($body);
            return $this->respond($res, $result, 200);
        } catch (Exception $e) {
            error_log('Save draft controller error: ' . $e->getMessage());
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getApplicationDraftController($req, $res) {
        try {
            $userId = $req->getAttribute('id');
            $draft = $this->applicationDraftService->getApplicationDraftService($userId);
            
            if (!$draft) {
                return $this->respond($res, null, 404);
            }
            
            return $this->respond($res, $draft, 200);
        } catch (Exception $e) {
            error_log('Get draft controller error: ' . $e->getMessage());
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function getAllDraftsController($req, $res) {
        try {
            $result = $this->applicationDraftService->getAllDraftsService();
            return $this->respond($res, $result, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteApplicationDraftController($req, $res) {
        try {
            $userId = $req->getAttribute('id');
            $result = $this->applicationDraftService->deleteApplicationDraftService($userId);
            return $this->respond($res, $result, 200);
        } catch (Exception $e) {
            error_log('Delete draft controller error: ' . $e->getMessage());
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 500);
        }
    }
}