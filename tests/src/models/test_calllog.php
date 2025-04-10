<?php
require_once __DIR__ . '/../../../server/src/models/callLogModel.php';
require_once __DIR__ . '/../../../server/database/db.php';

use MongoDB\BSON\ObjectId;

try {
    // Get database connection
    $db = Database::getDb();  

    // Create a new CallLog object
    $newCallLog = new CallLog("Noise Complaint", "679786771f972a04ce8d1c3a");

    // Insert into MongoDB
    $insertResult = $db->selectCollection('calllogs')->insertOne(json_decode(json_encode($newCallLog), true));
    $insertedId = $insertResult->getInsertedId();

    // Retrieve the inserted document
    $retrievedCallLog = $db->selectCollection('calllogs')->findOne(['_id' => new ObjectId($insertedId)]);

    // Test Output
    echo "✅ Test Passed: Successfully inserted and retrieved CallLog.\n";
    echo "Inserted Document:\n";
    print_r($retrievedCallLog);
} catch (Exception $e) {
    echo "❌ Test Failed: " . $e->getMessage() . "\n";
}
