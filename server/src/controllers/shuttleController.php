<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../services/shuttleService.php';

use Dotenv\Dotenv;
use Slim\Psr7\Response;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class ShuttleController {
    private $shuttleService;
    private $shuttleCollection;

    public function __construct() {
        $db = Database::getDb();
        $this->shuttleCollection = $db->Shuttle;
        $this->shuttleService = new shuttleService();
    }

    protected function respond(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public function createShuttleController($req, $res) {
        try {
            $shuttleDetails = $req->getParsedBody();

            $shuttle = $this->shuttleService->createShuttleService($shuttleDetails);

            return $this->respond($res, [
                'message' => 'Shuttle created successfully',
                'shuttle' => $shuttle
            ], 201);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function findShuttleByIdController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $shuttle = $this->shuttleService->findShuttleByIdService($id );

            return $this->respond($res,  $shuttle, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findAllMyShuttlesController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $shuttles = $this->shuttleService->findAllMyShuttlesService($id);

            return $this->respond($res,  $shuttles, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findAllShuttlesController($req, $res) {
        try {
            $shuttles = $this->shuttleService->findAllShuttlesService();

            return $this->respond($res,  $shuttles, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function updateShuttleController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $shuttleDetails = $req->getParsedBody();

            $updatedShuttle = $this->shuttleService->updateShuttleService($id, $shuttleDetails);
            
            return $this->respond($res,  $updatedShuttle, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function deleteShuttleController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $this->shuttleService->deleteShuttleService($id);
            return $this->respond($res,  ['message' => 'Shuttle deleted successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }
}