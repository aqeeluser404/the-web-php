<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../services/visitorService.php';

use Slim\Psr7\Response;

class VisitorController {
    private $visitorService;
    private $visitorCollection;

    public function __construct() {
        $db = Database::getDb();
        $this->visitorCollection = $db->Visitor;
        $this->visitorService = new visitorService();
    }

    protected function respond(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public function createVisitorController($req, $res) {
        try {
            $visitorDetails = $req->getParsedBody();

            $visitor = $this->visitorService->createVisitorService($visitorDetails);

            return $this->respond($res, [
                'message' => 'Visitor created successfully',
                'visitor' => $visitor
            ], 201);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function findVisitorByIdController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $visitor = $this->visitorService->findVisitorByIdService($id);

            return $this->respond($res, $visitor, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findAllMyVisitorsController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $visitors = $this->visitorService->findAllMyVisitorsService($id);

            return $this->respond($res, $visitors, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findAllVisitorsController($req, $res) {
        try {
            $visitors = $this->visitorService->findAllVisitorsService();

            return $this->respond($res, $visitors, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function updateVisitorController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $visitorDetails = $req->getParsedBody();

            $updatedVisitor = $this->visitorService->updateVisitorService($id, $visitorDetails);
            
            return $this->respond($res, $updatedVisitor, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function deleteVisitorController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $this->visitorService->deleteVisitorService($id);
            return $this->respond($res, ['message' => 'Visitor deleted successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

}