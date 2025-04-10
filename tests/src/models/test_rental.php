<?php
require_once __DIR__ . '/../../../server/src/models/rentalModel.php';
require_once __DIR__ . '/../../../server/database/db.php';

use MongoDB\BSON\ObjectId;

try {
    // Get database connection
    $db = Database::getDb();  

    // Retrieve a rental document
    $retrievedRental = $db->selectCollection('Rental')->findOne(['_id' => new ObjectId('67c1736cddae25f7791f8802')]);

    // Test Output
    print_r($retrievedRental);
} catch (Exception $e) {
    echo "❌ Test Failed: " . $e->getMessage() . "\n";
}