<?php
file_put_contents(__DIR__ . '/cron_output.log', "[" . date('Y-m-d H:i:s') . "] Script started\n", FILE_APPEND);

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/models/userModel.php'; 
// require_once __DIR__ . '/../src/models/rentalModel.php';
require_once __DIR__ . '/../src/services/rentalService.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;
use DateTime;
use Exception;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad(); 

function logMessage($message) {
    $logFile = __DIR__ . '/cron_output.log';
    $maxSize = 100 * 1024 * 1024; // 100MB in bytes
    
    // Rotate log if it exceeds max size
    if (file_exists($logFile)) {
        if (filesize($logFile) >= $maxSize) {
            $backupFile = __DIR__ . '/cron_output_' . date('Y-m-d_His') . '.log';
            rename($logFile, $backupFile);
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Log rotated\n");
            
            // Optional: Keep only last 5 backups
            $logFiles = glob(__DIR__ . '/cron_output_*.log');
            if (count($logFiles) > 5) {
                usort($logFiles, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                unlink($logFiles[0]);
            }
        }
    }
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL, FILE_APPEND);
}

class TokenChecker {
    private $userModel;
    private $rentalModel;
    private $rentalService;

    public function __construct() {
        $db = Database::getDb();
        $this->userModel = $db->User;
        $this->rentalModel = $db->Rental;
        $this->rentalService = new RentalService(); 
        
        logMessage("Database connection initialized.");
    }

    public function checkTokens() {
        logMessage("Starting token validation...");
        $users = $this->userModel->find(['loginInfo.isLoggedIn' => true]);

        if (empty($users)) {
            logMessage("No logged-in users found.");
        }

        foreach ($users as $user) {
            logMessage("Processing user ID: {$user['_id']}");
            $token = $user['loginInfo']['loginToken'];
            logMessage("User Token: " . ($token ? $token : "No token found"));

            if ($token) {
                try {
                    JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
                    logMessage("Token for user {$user['_id']} is valid.");
                } catch (Exception $e) {
                    logMessage("Token for user {$user['_id']} is invalid or expired: " . $e->getMessage());
                    if ($e instanceof \Firebase\JWT\ExpiredException) {
                        $this->userModel->updateOne(
                            ['_id' => $user['_id']],
                            ['$set' => ['loginInfo.isLoggedIn' => false, 'loginInfo.loginToken' => null]]
                        );
                        logMessage("User {$user['_id']} logged out due to expired token.");
                        $this->callLogoutEndpoint($user['_id']);
                    }
                }
            }

            $this->checkRentalsToEnd();
        }
        logMessage("Token validation completed.");
    }

    private function callLogoutEndpoint($userId) {
        $client = new \GuzzleHttp\Client();
        logMessage("Calling logout endpoint for user ID: {$userId}");

        try {
            $response = $client->post($_ENV['BACKEND_HOST_LINK'] . "/auth/logout/{$userId}");
            logMessage("Logout endpoint responded with status: " . $response->getStatusCode());
            logMessage("Logout endpoint response body: " . $response->getBody());
        } catch (Exception $error) {
            logMessage("Failed to call logout endpoint for user {$userId}: " . $error->getMessage());
        }
    }
    
    // private function checkRentalsToEnd() {
    //     $now = new DateTime();
    //     $rentalsToEnd = $this->rentalModel->find([
    //         'rentalEndDate' => ['$lte' => $now->format('Y-m-d H:i:s')],
    //         'status' => 'Active'
    //     ]);

    //     foreach ($rentalsToEnd as $rental) {
    //         $this->rentalService->endRentalService($rental['_id']);
    //     }
    // }

    private function checkRentalsToEnd() {
        $now = new DateTime();
        $nowFormatted = $now->format('Y-m-d H:i:s');
        logMessage("===== STARTING RENTAL END CHECK PROCESS =====");
        logMessage("Current time: {$nowFormatted}");
    
        try {
            // Debug MongoDB connection
            // logMessage("MongoDB connection status: " . json_encode(Database::getDb()->getManager()->getServers()));
    
            // Build query with explicit UTCDateTime conversion
            $query = [
                'status' => 'Active',
                'rentalEndDate' => [
                    '$lte' => new MongoDB\BSON\UTCDateTime($now->getTimestamp() * 1000)
                ]
            ];
            // logMessage("Executing query: " . json_encode($query));
            
            $cursor = $this->rentalModel->find($query);
            $rentalsToEnd = iterator_to_array($cursor);
            $count = count($rentalsToEnd);
            
            logMessage("Found {$count} rentals to process");
    
            if ($count === 0) {
                logMessage("No active rentals past their end date found");
                logMessage("===== COMPLETED RENTAL END CHECK PROCESS =====");
                return;
            }
            foreach ($rentalsToEnd as $rental) {
                $rentalId = (string)$rental['_id'];
                $endDate = $rental['rentalEndDate'] instanceof MongoDB\BSON\UTCDateTime 
                    ? $rental['rentalEndDate']->toDateTime()->format('Y-m-d H:i:s')
                    : (string)$rental['rentalEndDate'];
                
                logMessage("Processing rental ID: {$rentalId}");
                logMessage("- End date: {$endDate}");
                logMessage("- Unit ID: " . (string)$rental['unit']);
                logMessage("- User ID: " . (string)$rental['user']);
    
                try {
                    logMessage("Attempting to end rental...");
                    $result = $this->rentalService->endRentalService($rentalId);
                    logMessage("Rental ended successfully. Result: " . json_encode($result));
                } catch (Exception $e) {
                    logMessage("ERROR ending rental:");
                    logMessage("- Message: " . $e->getMessage());
                    logMessage("- Trace: " . $e->getTraceAsString());
                    continue;
                }
            }
            logMessage("===== SUCCESSFULLY COMPLETED RENTAL END CHECK PROCESS =====");
        } catch (Exception $e) {
            logMessage("CRITICAL ERROR IN RENTAL END CHECK:");
            logMessage("- Message: " . $e->getMessage());
            logMessage("- Trace: " . $e->getTraceAsString());
            logMessage("===== FAILED RENTAL END CHECK PROCESS =====");
        }
    }
}

// Run the script if executed directly
if (php_sapi_name() === 'cli') {
    $checker = new TokenChecker();
    $checker->checkTokens();
}