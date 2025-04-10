<?php
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../services/callLogService.php';

use Dotenv\Dotenv;
use Slim\Psr7\Response;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class CallLogController {
    private $callLogService;
    private $callLogCollection;

    public function __construct() {
        $db = Database::getDb();
        $this->callLogCollection = $db->calllogs;
        $this->callLogService = new CallLogService();
    }

    protected function respond(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public function createCallLogController($req, $res) {
        try {
            $callLogDetails = $req->getParsedBody();

            $callLog = $this->callLogService->createCallLogService($callLogDetails);

            return $this->respond($res, [
                'message' => 'Call log created successfully',
                'callLog' => $callLog
            ], 201);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function findCallLogByIdController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $callLog = $this->callLogService->findCallLogByIdService($id );

            return $this->respond($res,  $callLog, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findAllMyCallLogsController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $callLogs = $this->callLogService->findAllMyCallLogsService($id);

            return $this->respond($res,  $callLogs, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findAllCallLogsController($req, $res) {
        try {
            $callLogs = $this->callLogService->findAllCallLogsService();

            return $this->respond($res,  $callLogs, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function updateCallLogStatusController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $callLogDetails = $req->getParsedBody();

            $updatedCallLog = $this->callLogService->updateCallLogService($id, $callLogDetails);
            
            return $this->respond($res,  $updatedCallLog, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function deleteCallLogController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $this->callLogService->deleteCallLogService($id);
            return $this->respond($res,  ['message' => 'Call log deleted successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }
}