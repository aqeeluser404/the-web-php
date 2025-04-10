<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use MongoDB\Client;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

class Database {
    private static $client = null;
    private static $db = null;

    public static function connect() {
        try {
            $mongoUri = $_ENV['MONGODB_URL'] ?? null;
            if (!$mongoUri) {
                throw new Exception("MONGODB_URL is not set in the environment");
            }

            self::$client = new Client($mongoUri);

            $dbName = trim(parse_url($mongoUri, PHP_URL_PATH), "/");

            if (!$dbName) {
                throw new Exception("No database name found in MONGODB_URL");
            }

            self::$db = self::$client->selectDatabase($dbName);
            // echo "✅ Successfully Connected to DB: $dbName\n";
        } catch (Exception $e) {
            error_log("❌ Error Connecting to DB: " . $e->getMessage());
            exit("Database Connection Failed\n");
        }
    }

    public static function getDb() {
        if (self::$db === null) {
            self::connect();
        }
        return self::$db;
    }
}