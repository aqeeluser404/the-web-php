<?php
require_once __DIR__ . '/../../../server/src/models/unitModel.php';
require_once __DIR__ . '/../../../server/database/db.php';

use MongoDB\BSON\ObjectId;

try {
    // Get database connection
    $db = Database::getDb();  

    // Retrieve a unit document
    $retrievedUnit = $db->selectCollection('Unit')->findOne(['_id' => new ObjectId('67ab2a086129fa4f74d81d2e')]);

    // Test Output
    print_r($retrievedUnit);
} catch (Exception $e) {
    echo "❌ Test Failed: " . $e->getMessage() . "\n";
}