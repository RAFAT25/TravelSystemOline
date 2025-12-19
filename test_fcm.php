<?php
require 'fcm_v1_manual.php';

// Get FCM token from environment or pass it as a query parameter for testing
// Example: test_fcm.php?token=YOUR_FCM_TOKEN
$testToken = getenv('TEST_FCM_TOKEN') ?: ($_GET['token'] ?? '');

if (empty($testToken)) {
    die(json_encode([
        'success' => false,
        'error' => 'FCM token is required. Set TEST_FCM_TOKEN env var or pass ?token=YOUR_TOKEN'
    ]));
}

try {
    $title = 'Test HTTP v1';
    $body  = 'This is a test message from PHP';

    $res = sendFcmV1ToTokenManual(
        $testToken,
        $title,
        $body,
        ['type' => 'test']
    );

    echo '<pre>';
    print_r($res);
    echo '</pre>';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
