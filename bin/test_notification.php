<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Travel\Services\FcmService;
use Dotenv\Dotenv;

// Load env (if needed for other configs, mainly for DB or if we used env for key path)
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

$token = $argv[1] ?? null;

if (!$token) {
    echo "Usage: php bin/test_notification.php <device_token>\n";
    exit(1);
}

echo "Attempting to send notification to: " . substr($token, 0, 15) . "...\n";

$fcm = new FcmService();
$result = $fcm->sendNotification(
    $token, 
    "CLI Test", 
    "This is a test from your command line at " . date("H:i:s")
);

if ($result['success']) {
    echo "✅ Success! Notification sent.\n";
} else {
    echo "❌ Failed: " . $result['error'] . "\n";
}
