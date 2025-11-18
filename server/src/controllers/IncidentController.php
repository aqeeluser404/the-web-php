<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../services/IncidentService.php';

use Dotenv\Dotenv;
use Slim\Psr7\Response;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class IncidentController {
    private $incidentService;
    private $incidentCollection;

    public function __construct() {
        $db = Database::getDb();
        $this->incidentCollection = $db->Incidents;
        $this->incidentService = new IncidentService();
    }

    protected function respond(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public function createIncidentController($req, $res) {
        try {
            $incidentDetails = $req->getParsedBody();
            $incident = $this->incidentService->createIncidentService($incidentDetails);
            return $this->respond($res, [
                'message' => 'Incident created successfully',
                'incident' => $incident
            ], 201);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function findIncidentByIdController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $incident = $this->incidentService->findIncidentByIdService($id);

            return $this->respond($res,  $incident, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function findAllIncidentsController($req, $res) {
        try {
            $incidents = $this->incidentService->findAllIncidentsService();

            return $this->respond($res,  $incidents, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function updateIncidentController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $incidentDetails = $req->getParsedBody();

            $updatedIncident = $this->incidentService->updateIncidentService($id, $incidentDetails);
            
            return $this->respond($res,  $updatedIncident, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function deleteIncidentController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $this->incidentService->deleteIncidentService($id);
            return $this->respond($res,  ['message' => 'Incident deleted successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }
}