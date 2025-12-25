<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Travel\Services\Whapi;
use Dotenv\Dotenv;

// Load env
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
}

$phone   = $argv[1] ?? null;
$message = $argv[2] ?? "هذه رسالة تجريبية من نظام السفر الخاص بك 🚌";

if (!$phone) {
    echo "Usage: php bin/test_whatsapp.php <phone_number> [message]\n";
    echo "Example: php bin/test_whatsapp.php 770000000 \"مرحبا بك\"\n";
    exit(1);
}

echo "Attempting to send WhatsApp message to: $phone...\n";

try {
    $result = Whapi::sendText($phone, $message);
    
    if (isset($result['sent']) && $result['sent'] === true) {
        echo "✅ Success! Message sent.\n";
        echo "Response ID: " . ($result['id'] ?? 'N/A') . "\n";
    } else {
        echo "⚠️ Potential issue. Response: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Exception $e) {
    echo "❌ Failed: " . $e->getMessage() . "\n";
    echo "Tip: Check if WHAPI_TOKEN is set in your .env file.\n";
}
