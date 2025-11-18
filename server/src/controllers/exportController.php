<?php
require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Slim\Psr7\Response;
use MongoDB\Model\BSONDocument;

class ExportController {
    private $db;

    public function __construct() {
        $this->db = Database::getDb();
    }

    public function exportCalllogsCollection($request, $response): Response
    {
        try {
            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0); // Remove default sheet

            $collection = $this->db->selectCollection('calllogs');
            $data = $collection->find()->toArray();

            if (empty($data)) {
                throw new \Exception("No calllogs found in database");
            }

            $sheet = $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));
            $sheet->setTitle('Calllogs');

            // Extract headers from first document
            $firstDoc = (array)$data[0];
            $headers = array_keys($firstDoc);
            $sheet->fromArray($headers, null, 'A1');

            // Fill rows
            $rowIndex = 2;
            foreach ($data as $document) {
                $rowData = [];
                foreach ($headers as $header) {
                    $value = $this->convertMongoValue($document[$header] ?? null);
                    $rowData[] = $value;
                }
                $sheet->fromArray($rowData, null, "A{$rowIndex}");
                $rowIndex++;
            }

            // Stream to browser
            $tempFile = tempnam(sys_get_temp_dir(), 'calllogs_export_');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            $response = $response->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                                ->withHeader('Content-Disposition', 'attachment; filename="calllogs_export_'.date('Y-m-d').'.xlsx"')
                                ->withHeader('Content-Length', filesize($tempFile));

            $response->getBody()->write(file_get_contents($tempFile));
            unlink($tempFile);

            return $response;

        } catch (\Exception $e) {
            error_log("Export failed: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'error' => 'Export failed',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(500)
                            ->withHeader('Content-Type', 'application/json');
        }
    }

    public function exportUserCollection($request, $response): Response
    {
        try {
            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0); // Remove default sheet

            $collection = $this->db->selectCollection('User');
            $data = $collection->find()->toArray();

            if (empty($data)) {
                throw new \Exception("No users found in database");
            }

            $sheet = $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));
            $sheet->setTitle('Users');

            // Extract headers from first document
            $firstDoc = (array)$data[0];
            $headers = array_keys($firstDoc);
            $sheet->fromArray($headers, null, 'A1');

            // Fill rows
            $rowIndex = 2;
            foreach ($data as $document) {
                $rowData = [];
                foreach ($headers as $header) {
                    $value = $this->convertMongoValue($document[$header] ?? null);
                    $rowData[] = $value;
                }
                $sheet->fromArray($rowData, null, "A{$rowIndex}");
                $rowIndex++;
            }

            // Stream to browser
            $tempFile = tempnam(sys_get_temp_dir(), 'users_export_');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            $response = $response->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                                ->withHeader('Content-Disposition', 'attachment; filename="users_export_'.date('Y-m-d').'.xlsx"')
                                ->withHeader('Content-Length', filesize($tempFile));

            $response->getBody()->write(file_get_contents($tempFile));
            unlink($tempFile);

            return $response;

        } catch (\Exception $e) {
            error_log("Export failed: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'error' => 'Export failed',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(500)
                            ->withHeader('Content-Type', 'application/json');
        }
    }

    public function exportUnitCollection($request, $response): Response
    {
        try {
            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0); // Remove default sheet

            $collection = $this->db->selectCollection('Unit');
            $data = $collection->find()->toArray();

            if (empty($data)) {
                throw new \Exception("No units found in database");
            }

            $sheet = $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));
            $sheet->setTitle('Units');

            // Extract headers from first document
            $firstDoc = (array)$data[0];
            $headers = array_keys($firstDoc);
            $sheet->fromArray($headers, null, 'A1');

            // Fill rows
            $rowIndex = 2;
            foreach ($data as $document) {
                $rowData = [];
                foreach ($headers as $header) {
                    $value = $this->convertMongoValue($document[$header] ?? null);
                    $rowData[] = $value;
                }
                $sheet->fromArray($rowData, null, "A{$rowIndex}");
                $rowIndex++;
            }

            // Stream to browser
            $tempFile = tempnam(sys_get_temp_dir(), 'units_export_');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            $response = $response->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                                ->withHeader('Content-Disposition', 'attachment; filename="units_export_'.date('Y-m-d').'.xlsx"')
                                ->withHeader('Content-Length', filesize($tempFile));

            $response->getBody()->write(file_get_contents($tempFile));
            unlink($tempFile);

            return $response;

        } catch (\Exception $e) {
            error_log("Export failed: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'error' => 'Export failed',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(500)
                            ->withHeader('Content-Type', 'application/json');
        }
    }

    public function exportRentalCollection($request, $response): Response
    {
        try {
            $spreadsheet = new Spreadsheet();
            $spreadsheet->removeSheetByIndex(0); // Remove default sheet

            $collection = $this->db->selectCollection('Rental');
            $data = $collection->find()->toArray();

            if (empty($data)) {
                throw new \Exception("No rentals found in database");
            }

            // Create and activate new sheet
            $sheet = $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));
            $sheet->setTitle('Rentals');

            // Extract headers from first document
            $firstDoc = (array)$data[0];
            $headers = array_keys($firstDoc);
            $sheet->fromArray($headers, null, 'A1');

            // Fill rows
            $rowIndex = 2;
            foreach ($data as $document) {
                $rowData = [];
                foreach ($headers as $header) {
                    $value = $this->convertMongoValue($document[$header] ?? null);
                    $rowData[] = $value;
                }
                $sheet->fromArray($rowData, null, "A{$rowIndex}");
                $rowIndex++;
            }

            // Stream to browser
            $tempFile = tempnam(sys_get_temp_dir(), 'rentals_export_');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            $response = $response->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                                ->withHeader('Content-Disposition', 'attachment; filename="rentals_export_'.date('Y-m-d').'.xlsx"')
                                ->withHeader('Content-Length', filesize($tempFile));

            $response->getBody()->write(file_get_contents($tempFile));
            unlink($tempFile);

            return $response;

        } catch (\Exception $e) {
            error_log("Export failed: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'error' => 'Export failed',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(500)
                            ->withHeader('Content-Type', 'application/json');
        }
    }

    public function exportAllCollections($request, $response): Response
    {
        try {
            $spreadsheet = new Spreadsheet();
            // Remove default sheet if no collections exist
            if ($spreadsheet->getSheetCount() > 0) {
                $spreadsheet->removeSheetByIndex(0);
            }

            $collections = $this->db->listCollections();
            
            if (empty($collections)) {
                throw new \Exception("No collections found in database");
            }

            foreach ($collections as $collectionInfo) {
                $collectionName = $collectionInfo->getName();
                $collection = $this->db->selectCollection($collectionName);
                $data = $collection->find()->toArray();

                if (!empty($data)) {
                    $sheet = $spreadsheet->createSheet();
                    $sheet->setTitle(substr($collectionName, 0, 31)); // Excel sheet name limit

                    // Convert MongoDB documents to exportable format
                    $exportData = [];
                    $headers = [];
                    
                    // Get headers from first document
                    $firstDoc = (array)$data[0];
                    $headers = array_keys($firstDoc);
                    $sheet->fromArray($headers, null, 'A1');

                    // Process each document
                    $rowIndex = 2;
                    foreach ($data as $document) {
                        $rowData = [];
                        foreach ($headers as $header) {
                            $value = $this->convertMongoValue($document->$header ?? null);
                            $rowData[] = $value;
                        }
                        $sheet->fromArray($rowData, null, "A{$rowIndex}");
                        $rowIndex++;
                    }
                }
            }

            // Stream to browser directly
            $tempFile = tempnam(sys_get_temp_dir(), 'mongo_export_');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            $response = $response->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                                ->withHeader('Content-Disposition', 'attachment; filename="mongo_export_'.date('Y-m-d').'.xlsx"')
                                ->withHeader('Content-Length', filesize($tempFile));

            $response->getBody()->write(file_get_contents($tempFile));
            unlink($tempFile);
            
            return $response;

        } catch (\Exception $e) {
            error_log("Export failed: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'error' => 'Export failed',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(500)
                           ->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Convert MongoDB values to Excel-compatible format
     */
    private function convertMongoValue($value)
    {
        if ($value instanceof MongoDB\BSON\ObjectId) {
            return (string)$value;
        }
        if ($value instanceof MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->format('Y-m-d H:i:s');
        }
        if ($value instanceof MongoDB\BSON\Binary) {
            return '[BINARY DATA]';
        }
        if ($value instanceof MongoDB\Model\BSONArray || $value instanceof MongoDB\Model\BSONDocument) {
            return json_encode($value);
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        if ($value === null) {
            return '';
        }
        return $value;
    }
}