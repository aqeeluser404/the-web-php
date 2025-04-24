<?php
// Logs a message indicating the script has started
file_put_contents(__DIR__ . '/cron_output.log', "[" . date('Y-m-d H:i:s') . "] Script started\n", FILE_APPEND);

// Include necessary files for database connection, autoloaders, and models
require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/models/userModel.php'; 
require_once __DIR__ . '/../src/services/rentalService.php';

// Import required namespaces and libraries
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;
use DateTime;
use Exception;

// Load environment variables from .env file
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad(); 

// Function to log messages to a file with a rotation mechanism to manage file size
function logMessage($message) {
    $logFile = __DIR__ . '/cron_output.log';
    $maxSize = 100 * 1024 * 1024; // Set maximum log file size to 100MB
    
    // If log file exists and exceeds the max size, rotate it
    if (file_exists($logFile)) {
        if (filesize($logFile) >= $maxSize) {
            $backupFile = __DIR__ . '/cron_output_' . date('Y-m-d_His') . '.log';
            rename($logFile, $backupFile);
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Log rotated\n");
            
            // Limit to last 5 backup files
            $logFiles = glob(__DIR__ . '/cron_output_*.log');
            if (count($logFiles) > 5) {
                usort($logFiles, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                unlink($logFiles[0]); // Delete oldest backup
            }
        }
    }
    // Append the new message to the log file
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL, FILE_APPEND);
}

// Main class to handle token checking for logged-in users
class TokenChecker {
    private $userModel;
    private $rentalModel;
    private $rentalService;

    // Constructor initializes database models and the rental service
    public function __construct() {
        $db = Database::getDb();
        $this->userModel = $db->User;
        $this->rentalModel = $db->Rental;
        $this->rentalService = new RentalService(); 
        
        logMessage("Database connection initialized.");
    }

    // Method to validate tokens for logged-in users
    public function checkTokens() {
        logMessage("Starting token validation...");
        $users = $this->userModel->find(['loginInfo.isLoggedIn' => true]);

        if (empty($users)) {
            logMessage("No logged-in users found.");
        }

        // Loop through each logged-in user
        foreach ($users as $user) {
            logMessage("Processing user ID: {$user['_id']}");
            $token = $user['loginInfo']['loginToken'];
            logMessage("User Token: " . ($token ? $token : "No token found"));

            // If a token exists, validate it using JWT
            if ($token) {
                try {
                    JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
                    logMessage("Token for user {$user['_id']} is valid.");
                } catch (Exception $e) {
                    logMessage("Token for user {$user['_id']} is invalid or expired: " . $e->getMessage());
                    // If token is expired, update user status and logout
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
            // Check and process rentals nearing their end
            $this->checkRentalsToEnd();
        }
        logMessage("Token validation completed.");
    }

    // Method to call the logout endpoint for a specific user
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

    // Method to find and process rentals that have reached their end date
    private function checkRentalsToEnd() {
        $now = new DateTime();
        $nowFormatted = $now->format('Y-m-d H:i:s');
        logMessage("===== STARTING RENTAL END CHECK PROCESS =====");
        logMessage("Current time: {$nowFormatted}");
    
        try {
            // Define query to find active rentals past their end date
            $query = [
                'status' => 'Active',
                'rentalEndDate' => [
                    '$lte' => new MongoDB\BSON\UTCDateTime($now->getTimestamp() * 1000)
                ]
            ];

            // Execute query and process the results
            $cursor = $this->rentalModel->find($query);
            $rentalsToEnd = iterator_to_array($cursor);
            $count = count($rentalsToEnd);
            
            logMessage("Found {$count} rentals to process");
    
            if ($count === 0) {
                logMessage("No active rentals past their end date found");
                logMessage("===== COMPLETED RENTAL END CHECK PROCESS =====");
                return;
            }

            // Loop through rentals to process their end
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

// If the script is executed directly, instantiate the class and run token validation
if (php_sapi_name() === 'cli') {
    $checker = new TokenChecker();
    $checker->checkTokens();
}