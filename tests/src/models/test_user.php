<?php
require_once __DIR__ . '/../../../server/src/models/userModel.php';
require_once __DIR__ . '/../../../server/database/db.php';

use MongoDB\BSON\ObjectId;

try {
    // Get database connection
    $db = Database::getDb();  

    // Retrieve a user document
    $retrievedUser = $db->selectCollection('User')->findOne(['_id' => new ObjectId('6788a0c6ada675dc8ced4d03')]);

    // Test Output
    print_r($retrievedUser);
} catch (Exception $e) {
    echo "❌ Test Failed: " . $e->getMessage() . "\n";
}