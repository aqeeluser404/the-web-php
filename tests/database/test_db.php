<?php
require_once __DIR__ . '/../../server/database/db.php';

try {
    $db = Database::getDb();
    echo "✅ Test Passed: Successfully connected to the database.\n";
} catch (Exception $e) {
    echo "❌ Test Failed: " . $e->getMessage() . "\n";
}
