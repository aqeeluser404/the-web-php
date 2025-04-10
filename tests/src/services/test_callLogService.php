<?php
require_once __DIR__ . '/../../../server/src/services/callLogService.php';

try {
    $callLogService = new CallLogService();

    // Test createCallLogService
    $callLogDetails = [
        'callType' => 'Noise Complaint',
        'user' => '6788a0c6ada675dc8ced4d03'
    ];

    $newCallLog = $callLogService->createCallLogService($callLogDetails);
    echo "✅ Test Passed: Successfully created CallLog.\n";
    print_r($newCallLog);

        // Ensure the new call log has an _id
        if (!isset($newCallLog['_id'])) {
            throw new Exception('New call log does not have an _id');
        }
        // Debug: Print the _id
        echo "Debug: New CallLog _id: " . $newCallLog['_id'] . "\n";
    

    // Test findCallLogByIdService
    $retrievedCallLog = $callLogService->findCallLogByIdService($newCallLog['_id']);
    echo "✅ Test Passed: Successfully retrieved CallLog by ID.\n";
    print_r($retrievedCallLog);

    // Test findAllMyCallLogsService
    $userCallLogs = $callLogService->findAllMyCallLogsService($callLogDetails['user']);
    echo "✅ Test Passed: Successfully retrieved all user CallLogs.\n";
    print_r($userCallLogs);

    // Test findAllCallLogsService
    $allCallLogs = $callLogService->findAllCallLogsService();
    echo "✅ Test Passed: Successfully retrieved all CallLogs.\n";
    print_r($allCallLogs);

    // Test updateCallLogService
    $updateDetails = ['status' => 'Resolved'];
    $updatedCallLog = $callLogService->updateCallLogService($newCallLog['_id'], $updateDetails);
    echo "✅ Test Passed: Successfully updated CallLog.\n";
    print_r($updatedCallLog);

    // Test deleteCallLogService
    $deleteResult = $callLogService->deleteCallLogService($newCallLog['_id']);
    echo "✅ Test Passed: Successfully deleted CallLog.\n";
    var_dump($deleteResult);

} catch (Exception $e) {
    echo "❌ Test Failed: " . $e->getMessage() . "\n";
}

