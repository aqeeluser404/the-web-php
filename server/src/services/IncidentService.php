<?php
require_once __DIR__ . '/../../database/db.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;
use MongoDB\Operation\FindOneAndUpdate;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

class IncidentService {
    private $incidentCollection;

    public function __construct() {
        $db = Database::getDb();
        $this->incidentCollection = $db->Incidents;
    }

    protected function safeDateFormat($dateValue) {
        if ($dateValue instanceof UTCDateTime) {
            return $dateValue->toDateTime()->format('Y-m-d\TH:i:s.vP');
        }
        if (is_string($dateValue)) {
            return $dateValue;
        }
        return null; 
    }

    private function generateCustomId(): string {
        return (string) mt_rand(100000, 999999);
    }

    public function createIncidentService(array $incidentDetails): array {
        try {
            if (empty($incidentDetails['incidentNature']) || empty($incidentDetails['description'])) {
                throw new Exception('Missing required fields');
            }
            $attempts = 0;
            do {
                $logNumber = $this->generateCustomId();
                $existingIncident = $this->incidentCollection->findOne(['logNumber' => $logNumber]);
                if ($existingIncident && $attempts++ > 3) {
                    error_log("Multiple collisions generating logNumber");
                }
            } while ($existingIncident !== null);

            $incidentData = [
                'logNumber' => $logNumber,
                'firstName' => $incidentDetails['firstName'],
                'lastName' => $incidentDetails['lastName'],
                'email' => $incidentDetails['email'],
                'phone' => $incidentDetails['phone'],
                'incidentNature' => $incidentDetails['incidentNature'],
                'createdAt' => new UTCDateTime(),
                'location' => $incidentDetails['location'],
                'description' => $incidentDetails['description'],
            ];

            $insertResult = $this->incidentCollection->insertOne($incidentData);
            return $incidentData;
        } catch (Exception $error) {
            error_log('Incident creation failed: ' . $error->getMessage());
            throw $error;
        }
    }

    public function findIncidentByIdService($id) {
        try {
            $incident = $this->incidentCollection->findOne(['_id' => new ObjectId($id)]);
            if (!$incident) {
                throw new Exception('Incident not found');
            }
            return $incident;
        } catch (Exception $error) {
            error_log('Error finding incident by ID: ' . $error->getMessage());
            throw $error;
        }
    }

    public function findAllIncidentsService() {
        try {
            $incidents = $this->incidentCollection->find();
            
            $results = [];
            foreach ($incidents as $doc) {
                $incident = [
                    '_id' => (string)$doc['_id'],
                    'logNumber' => $doc['logNumber'] ?? null,
                    'firstName' => $doc['firstName'],
                    'lastName' => $doc['lastName'],
                    'email' => $doc['email'],
                    'phone' => $doc['phone'],
                    'incidentNature' => $doc['incidentNature'] ?? 'Other',
                    'createdAt' => $this->safeDateFormat($doc['createdAt'] ?? null),
                    'location' => $doc['location'],
                    'description' => $doc['description'],
                ];
                $results[] = $incident;
            }
            return $results;
        } catch (Exception $error) {
            error_log('Error finding call logs: ' . $error->getMessage());
            throw $error;
        }
    }

    public function updateIncidentService($incidentId, $incidentDetails) {
        try {
            $incidentToUpdate = $this->incidentCollection->findOneAndUpdate(
                ['_id' => new ObjectId($incidentId)],
                ['$set' => $incidentDetails],
                ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
            if (!$incidentToUpdate) {
                throw new Exception('Incident not found');
            }
            return $incidentToUpdate;
        } catch (Exception $error) {
            error_log('Error updating incident: ' . $error->getMessage());
            throw $error;
        }
    }

    public function deleteIncidentService($incidentId) {
        try {
            $incident = $this->incidentCollection->findOne(['_id' => new ObjectId($incidentId)]);
            if (!$incident) {
                throw new Exception('Incident not found');
            }
            $this->incidentCollection->deleteOne(['_id' => new ObjectId($incidentId)]);
            return true;
        } catch (Exception $error) {
            error_log('Error deleting incident: ' . $error->getMessage());
            throw $error;
        }
    }
}