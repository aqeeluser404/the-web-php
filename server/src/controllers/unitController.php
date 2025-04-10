<?php
require_once __DIR__ . '/../services/unitService.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use Dotenv\Dotenv;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class UnitController {
    private $unitController;
    private $unitService;

    public function __construct() {
        $db = Database::getDb();
        $this->unitController = $db->Unit;
        $this->unitService = new UnitService();
    }

    protected function respond(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public function createUnitController($req, $res) {
        try {
            $userDetails = $req->getParsedBody();
            
            $uploadedFiles = [];            // MANUALLY PROCESS UPLOADED FILES
            
            if (!empty($_FILES['images']['name'][0])) {                             // Multiple files
                foreach ($_FILES['images']['name'] as $index => $name) {
                    if ($_FILES['images']['error'][$index] === UPLOAD_ERR_OK) {
                        $uploadedFiles[] = new UploadedFile(
                            $_FILES['images']['tmp_name'][$index],
                            $name,
                            $_FILES['images']['type'][$index],
                            $_FILES['images']['size'][$index],
                            $_FILES['images']['error'][$index]
                        );
                    }
                }
            } elseif (!empty($_FILES['images']['name'])) {                           // Single file
                $uploadedFiles[] = new UploadedFile(
                    $_FILES['images']['tmp_name'],
                    $_FILES['images']['name'],
                    $_FILES['images']['type'],
                    $_FILES['images']['size'],
                    $_FILES['images']['error']
                );
            }
            
            $result = $this->unitService->createUnitService(
                $userDetails,
                ['images' => $uploadedFiles]
            );
            
            return $this->respond($res, [
                'message' => 'Unit created successfully',
                'data' => $result
            ], 201);
        } catch (Exception $e) {
            error_log('Controller error: ' . $e->getMessage());
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    public function findUnitByIdController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $unit = $this->unitService->findUnitByIdService($id);
            return $this->respond($res,  $unit, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findAllUnitsController($req, $res) {
        try {
            $units = $this->unitService->findAllUnitsService();
            return $this->respond($res,  $units, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function updateUnitController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $unitDetails = $req->getParsedBody();

            $this->unitService->updateUnitService($id, $unitDetails);
            return $this->respond($res,  ['message' => 'Unit updated successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function deleteUnitController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $this->unitService->deleteUnitService($id);
            return $this->respond($res,  ['message' => 'Unit deleted successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }
}