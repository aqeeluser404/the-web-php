<?php
// Load Composer's autoloader for external libraries and dependencies
require_once __DIR__ . '/../../vendor/autoload.php';

// Import necessary namespaces for MongoDB and dotenv functionality
use MongoDB\Client;
use Dotenv\Dotenv;

// Initialize dotenv to load environment variables from the .env file
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load(); // Load all environment variables into $_ENV

class Database {
    // Static properties to hold MongoDB client and database instances
    private static $client = null;
    private static $db = null;

    // Establish connection to the MongoDB database
    public static function connect() {
        try {
            // Retrieve the MongoDB URI from environment variables
            $mongoUri = $_ENV['MONGODB_URL'] ?? null;
            if (!$mongoUri) {
                // If the URI is not set, throw an exception
                throw new Exception("MONGODB_URL is not set in the environment");
            }

            // Create a new MongoDB client instance with the URI
            self::$client = new Client($mongoUri);

            // Extract the database name from the URI
            $dbName = trim(parse_url($mongoUri, PHP_URL_PATH), "/");
            if (!$dbName) {
                // If no database name is found in the URI, throw an exception
                throw new Exception("No database name found in MONGODB_URL");
            }

            // Select the database using the extracted name
            self::$db = self::$client->selectDatabase($dbName);

            // echo "✅ Successfully Connected to DB: $dbName\n"; // Uncomment the line for debugging successful connection
            
        } catch (Exception $e) {
            // Log connection errors to the error log and terminate script execution
            error_log("❌ Error Connecting to DB: " . $e->getMessage());
            exit("Database Connection Failed\n");
        }
    }

    // Method to retrieve the MongoDB database instance
    public static function getDb() {
        // If the database instance is not initialized, establish a connection
        if (self::$db === null) {
            self::connect();
        }
        return self::$db; // Return the initialized database instance
    }
}