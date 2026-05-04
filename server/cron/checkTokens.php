<?php
file_put_contents(__DIR__ . '/cron_output.log', "[" . date('Y-m-d H:i:s') . "] Script started\n", FILE_APPEND);

require_once __DIR__ . '/../database/db.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../src/models/userModel.php'; 
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
    $maxSize = 10 * 1024 * 1024;

    // Rotate logs if size exceeds the limit
    if (file_exists($logFile) && filesize($logFile) >= $maxSize) {
        $backupFile = __DIR__ . '/cron_output_' . date('Y-m-d_His') . '.log';
        rename($logFile, $backupFile);
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Log rotated\n");
        
        // Keep only the last 5 log backups
        $logFiles = glob(__DIR__ . '/cron_output_*.log');
        if (count($logFiles) > 5) {
            usort($logFiles, fn($a, $b) => filemtime($a) - filemtime($b));
            unlink($logFiles[0]); // Delete the oldest backup
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
        // logMessage("Database connection initialized.");
    }

    // public function checkTokens() {
    //     logMessage("======= USER LOGIN CHECK STARTED =======");

    //     $users = $this->userModel->find(['loginInfo.isLoggedIn' => true]);

    //     if (empty($users)) {
    //         logMessage("No logged-in users found.");
    //         logMessage("======= USER LOGIN CHECK COMPLETED =======");
    //         return;
    //     }

    //     foreach ($users as $user) {
    //         logMessage("Processing user ID: {$user['_id']}");
    //         $token = $user['loginInfo']['loginToken'] ?? "No token found";
    //         logMessage("User Token: {$token}");

    //         if ($token && $token !== "No token found") {
    //             try {
    //                 JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
    //                 logMessage("✅ Token for user {$user['_id']} is VALID.");
    //             } catch (Exception $e) {
    //                 logMessage("❌ Token for user {$user['_id']} is INVALID or EXPIRED: " . $e->getMessage());

    //                 if ($e instanceof \Firebase\JWT\ExpiredException) {
    //                     $this->userModel->updateOne(
    //                         ['_id' => $user['_id']],
    //                         ['$set' => ['loginInfo.isLoggedIn' => false, 'loginInfo.loginToken' => null]]
    //                     );
    //                     logMessage("🔴 User {$user['_id']} logged out due to expired token.");
    //                     $this->callLogoutEndpoint($user['_id']);
    //                 }
    //             }
    //         }

    //         logMessage("---------------------------------");
    //     }

    //     logMessage("======= USER LOGIN CHECK COMPLETED =======");
    //     $this->checkRentalsToEnd();
    // }

    public function checkTokens() {
        logMessage("======= USER LOGIN CHECK STARTED =======");

        $users = $this->userModel->find(['loginInfo.isLoggedIn' => true]);

        if (empty($users)) {
            logMessage("No logged-in users found.");
            logMessage("======= USER LOGIN CHECK COMPLETED =======");
            return;
        }

        foreach ($users as $user) {
            logMessage("Processing user ID: {$user['_id']}");
            
            $refreshToken = $user['loginInfo']['refreshToken'] ?? null;
            $token = $user['loginInfo']['loginToken'] ?? "No token found";
            logMessage("User Token: {$token}");

            if ($token && $token !== "No token found") {
                try {
                    JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
                    logMessage("✅ Token for user {$user['_id']} is VALID.");
                } catch (Exception $e) {
                    logMessage("❌ Token for user {$user['_id']} is INVALID or EXPIRED: " . $e->getMessage());

                    if ($e instanceof \Firebase\JWT\ExpiredException) {
                        $this->userModel->updateOne(
                            ['_id' => $user['_id']],
                            ['$set' => ['loginInfo.isLoggedIn' => false, 'loginInfo.loginToken' => null]]
                        );
                        logMessage("🔴 User {$user['_id']} logged out due to expired token.");
                        $this->callLogoutEndpoint($user['_id']);
                    }
                }
            }

            if ($refreshToken) {
                try {
                    JWT::decode($refreshToken, new Key($_ENV['JWT_SECRET'], 'HS256'));
                    logMessage("✅ Refresh token for user {$user['_id']} is VALID.");
                } catch (Exception $e) {
                    logMessage("❌ Refresh token for user {$user['_id']} is INVALID or EXPIRED: " . $e->getMessage());
                    if ($e instanceof \Firebase\JWT\ExpiredException) {
                        $this->userModel->updateOne(
                            ['_id' => $user['_id']],
                            ['$set' => [
                                'loginInfo.isLoggedIn' => false,
                                'loginInfo.loginToken' => null,
                                'loginInfo.refreshToken' => null
                            ]]
                        );
                        logMessage("🔴 User {$user['_id']} logged out due to expired refresh token.");
                        $this->callLogoutEndpoint($user['_id']);
                    }
                }
            }

            logMessage("---------------------------------");
        }

        logMessage("======= USER LOGIN CHECK COMPLETED =======");
        $this->checkRentalsToEnd();
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

    private function checkRentalsToEnd() {
        logMessage("========== RENTAL CHECK STARTED ==========");

        $now = new DateTime();
        logMessage("Current Time: {$now->format('Y-m-d H:i:s')}");

        try {
            // Query rentals with UTCDateTime end dates
            $query = [
                'status' => 'Active',
                'rentalEndDate' => [
                    '$lte' => new MongoDB\BSON\UTCDateTime($now->getTimestamp() * 1000)
                ]
            ];
            $cursor = $this->rentalModel->find($query);
            $rentalsToEnd = iterator_to_array($cursor);

            // Also check rentals where rentalEndDate is stored as string
            $stringDateRentals = $this->rentalModel->find([
                'status' => 'Active',
                'rentalEndDate' => ['$type' => 'string']
            ]);

            foreach ($stringDateRentals as $rental) {
                try {
                    $endDateObj = new DateTime($rental['rentalEndDate']);
                    if ($endDateObj <= $now) {
                        $rentalsToEnd[] = $rental;
                    }
                } catch (Exception $e) {
                    logMessage("⚠️ Skipped rental {$rental['_id']} due to invalid date format: {$rental['rentalEndDate']}");
                }
            }

            $count = count($rentalsToEnd);
            logMessage("Found {$count} rentals to process.");

            if ($count === 0) {
                logMessage("No active rentals past their end date found.");
                logMessage("========== RENTAL CHECK COMPLETED ==========");
                logMessage(PHP_EOL . PHP_EOL);
                return;
            }

            foreach ($rentalsToEnd as $rental) {
                $rentalId = (string) $rental['_id'];
                $endDate = $rental['rentalEndDate'] instanceof MongoDB\BSON\UTCDateTime
                    ? $rental['rentalEndDate']->toDateTime()->format('Y-m-d H:i:s')
                    : (string) $rental['rentalEndDate'];

                logMessage("Processing Rental ID: {$rentalId}");
                logMessage("- End Date: {$endDate}");
                logMessage("- Unit ID: " . (string) $rental['unit']);
                logMessage("- User ID: " . (string) $rental['user']);

                try {
                    logMessage("Attempting to end rental...");
                    $result = $this->rentalService->endRentalService($rentalId);
                    logMessage("✅ Rental ended successfully. Result: " . json_encode($result));
                } catch (Exception $e) {
                    logMessage("❌ ERROR ending rental: " . $e->getMessage());
                    continue;
                }

                logMessage("--------------------");
            }

            logMessage("========== RENTAL CHECK COMPLETED ==========");
            logMessage(PHP_EOL . PHP_EOL);
        } catch (Exception $e) {
            logMessage("🚨 CRITICAL ERROR IN RENTAL CHECK: " . $e->getMessage());
            logMessage("========== RENTAL CHECK FAILED ==========");
            logMessage(PHP_EOL . PHP_EOL);
        }
    }
}

// If the script is executed directly, instantiate the class and run token validation
if (php_sapi_name() === 'cli') {
    $checker = new TokenChecker();
    $checker->checkTokens();
}