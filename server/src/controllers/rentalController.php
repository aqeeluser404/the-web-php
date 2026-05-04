<?php
require_once __DIR__ . '/../services/rentalService.php';
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

class RentalController {
    private $rentalController;
    private $rentalService;

    public function __construct() {
        $db = Database::getDb();
        $this->rentalController = $db->Rental;
        $this->rentalService = new RentalService();
    }

    protected function respond(Response $response, $data, int $status = 200): Response {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public function syncRentalController($req, $res) {
        try {
            $this->rentalService->syncRentalService();
            return $this->respond($res,  ['message' => 'Rental synced successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function createRentalController($req, $res) {
        try {
            $rentalDetails = $req->getParsedBody();

            $signatureFiles = [];

            if (!empty($_FILES['signatureImage']['name'][0])) { // array-style (future-proofing)
                foreach ($_FILES['signatureImage']['name'] as $index => $name) {
                    if ($_FILES['signatureImage']['error'][$index] === UPLOAD_ERR_OK) {
                        $signatureFiles[] = new UploadedFile(
                            $_FILES['signatureImage']['tmp_name'][$index],
                            $name,
                            $_FILES['signatureImage']['type'][$index],
                            $_FILES['signatureImage']['size'][$index],
                            $_FILES['signatureImage']['error'][$index]
                        );
                    }
                }
            } elseif (!empty($_FILES['signatureImage']['name'])) { // single file fallback
                $signatureFiles[] = new UploadedFile(
                    $_FILES['signatureImage']['tmp_name'],
                    $_FILES['signatureImage']['name'],
                    $_FILES['signatureImage']['type'],
                    $_FILES['signatureImage']['size'],
                    $_FILES['signatureImage']['error']
                );
            }

            $guardianSignatureFiles = [];

            if (!empty($_FILES['guardianSignatureImage']['name'][0])) { // array-style
                foreach ($_FILES['guardianSignatureImage']['name'] as $index => $name) {
                    if ($_FILES['guardianSignatureImage']['error'][$index] === UPLOAD_ERR_OK) {
                        $guardianSignatureFiles[] = new UploadedFile(
                            $_FILES['guardianSignatureImage']['tmp_name'][$index],
                            $name,
                            $_FILES['guardianSignatureImage']['type'][$index],
                            $_FILES['guardianSignatureImage']['size'][$index],
                            $_FILES['guardianSignatureImage']['error'][$index]
                        );
                    }
                }
            } elseif (!empty($_FILES['guardianSignatureImage']['name'])) { // single file fallback
                if ($_FILES['guardianSignatureImage']['error'] === UPLOAD_ERR_OK) {
                    $guardianSignatureFiles[] = new UploadedFile(
                        $_FILES['guardianSignatureImage']['tmp_name'],
                        $_FILES['guardianSignatureImage']['name'],
                        $_FILES['guardianSignatureImage']['type'],
                        $_FILES['guardianSignatureImage']['size'],
                        $_FILES['guardianSignatureImage']['error']
                    );
                }
            }

            $result = $this->rentalService->createRentalService($rentalDetails, $signatureFiles, $guardianSignatureFiles);

            return $this->respond($res, [
                'message' => 'Rental created successfully',
                'rental' => $result['rental'],
                'accessKey' => $result['accessKey']
            ], 201);
        } catch (Exception $e) {
            error_log('Controller error: ' . $e->getMessage());
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function deleteRentalController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $this->rentalService->deleteRentalService($id);
            return $this->respond($res,  ['message' => 'Rental deleted successfully'], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findRentalByIdController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $rental = $this->rentalService->findRentalByIdService($id);
            return $this->respond($res,  $rental, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findAllRentalsController($req, $res) {
        try {
            $rentals = $this->rentalService->findAllRentalsService();
            return $this->respond($res,  $rentals, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function findAllMyRentalsController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $rentals = $this->rentalService->findAllMyRentalsService($id);
            return $this->respond($res,  $rentals, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function updateRentalController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $rentalDetails = $req->getParsedBody();
            $updatedRental = $this->rentalService->updateRentalService($id, $rentalDetails);
            return $this->respond($res,  $updatedRental, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function reassignUnitController($req, $res) {
        try {
            $reassignDetails = $req->getParsedBody();
            $updatedRental = $this->rentalService->reassignUnitService($reassignDetails);
            return $this->respond($res,  $updatedRental, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function endRentalController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $updatedRental = $this->rentalService->endRentalService($id);
            return $this->respond($res,  $updatedRental, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function verifyAndSavePayerController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $rentalData = $req->getParsedBody();

            $verificationResult = $this->rentalService->verifyAndSavePayerService($id, $rentalData);
            return $this->respond($res, [
                'message' => 'Payer verified successfully',
                'isValidated' => $verificationResult['isValidated'],
                'score' => $verificationResult['score'] ?? null
            ], 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function earlyEndRentalController($req, $res) {
        try {
            $id = $req->getAttribute('id');
            $updatedRental = $this->rentalService->earlyEndRentalService($id);
            return $this->respond($res,  $updatedRental, 200);
        } catch (Exception $e) {
            return $this->respond($res, [
                'error' => $e->getMessage()
            ], 404);
        }
    }
}