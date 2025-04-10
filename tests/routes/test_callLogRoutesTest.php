<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../server/src/controllers/callLogController.php';
require_once __DIR__ . '/../../server/middleware/authentication.php';

use GuzzleHttp\Client;

try {
    $client = new Client([
        'base_uri' => 'http://localhost:8000', // Adjust the base URI as needed
        'cookies' => true
    ]);

    // Test createCallLog route
    $callLogDetails = [
        'callType' => 'Noise Complaint',
        'user' => '6788a0c6ada675dc8ced4d03'
    ];

    $response = $client->post('/call-log', [
        'json' => $callLogDetails
    ]);

    if ($response->getStatusCode() === 201) {
        echo "✅ Test Passed: Successfully created CallLog.\n";
        $newCallLog = json_decode($response->getBody(), true);
        print_r($newCallLog);

        // Ensure the new call log has an _id
        if (!isset($newCallLog['_id'])) {
            throw new Exception('New call log does not have an _id');
        }
        echo "Debug: New CallLog _id: " . $newCallLog['_id'] . "\n";
    } else {
        throw new Exception('Failed to create CallLog');
    }

    // Test findCallLogById route
    $response = $client->get('/call-log/' . $newCallLog['_id']);

    if ($response->getStatusCode() === 200) {
        echo "✅ Test Passed: Successfully retrieved CallLog by ID.\n";
        $retrievedCallLog = json_decode($response->getBody(), true);
        print_r($retrievedCallLog);
    } else {
        throw new Exception('Failed to retrieve CallLog by ID');
    }

    // Test findAllMyCallLogs route
    $response = $client->get('/users/' . $callLogDetails['user'] . '/call-logs');

    if ($response->getStatusCode() === 200) {
        echo "✅ Test Passed: Successfully retrieved all user CallLogs.\n";
        $userCallLogs = json_decode($response->getBody(), true);
        print_r($userCallLogs);
    } else {
        throw new Exception('Failed to retrieve all user CallLogs');
    }

    // Test findAllCallLogs route (admin)
    $response = $client->get('/admin/call-logs');

    if ($response->getStatusCode() === 200) {
        echo "✅ Test Passed: Successfully retrieved all CallLogs.\n";
        $allCallLogs = json_decode($response->getBody(), true);
        print_r($allCallLogs);
    } else {
        throw new Exception('Failed to retrieve all CallLogs');
    }

    // Test updateCallLogStatus route (admin)
    $updateDetails = ['status' => 'Resolved'];
    $response = $client->put('/admin/call-log/' . $newCallLog['_id'], [
        'json' => $updateDetails
    ]);

    if ($response->getStatusCode() === 200) {
        echo "✅ Test Passed: Successfully updated CallLog.\n";
        $updatedCallLog = json_decode($response->getBody(), true);
        print_r($updatedCallLog);
    } else {
        throw new Exception('Failed to update CallLog');
    }

    // Test deleteCallLog route
    $response = $client->delete('/call-log/' . $newCallLog['_id']);

    if ($response->getStatusCode() === 204) {
        echo "✅ Test Passed: Successfully deleted CallLog.\n";
    } else {
        throw new Exception('Failed to delete CallLog');
    }

} catch (Exception $e) {
    echo "❌ Test Failed: " . $e->getMessage() . "\n";
}