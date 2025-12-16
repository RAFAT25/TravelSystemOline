<?php

namespace Travel\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Exception;

class FcmService {
    private $messaging;

    public function __construct() {
        // Option 2: Env var contains JSON content directly (FIREBASE_CREDENTIALS_JSON)
        
        $factory = (new Factory());

        $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ($_ENV['GOOGLE_APPLICATION_CREDENTIALS'] ?? null);
        $jsonCredentials = getenv('FIREBASE_CREDENTIA') ?: ($_ENV['FIREBASE_CREDENTIA'] ?? null);
        $renderSecretPath = '/etc/secrets/firebase_key.json'; 
        $localPath = __DIR__ . '/../../secrets/firebase_key.json';

        // DEBUG: Output to Render Logs
        error_log("FCM DEBUG: FIREBASE_CREDENTIA length: " . strlen((string)$jsonCredentials));
        error_log("FCM DEBUG: GOOGLE_APPLICATION_CREDENTIA: " . $credentialsPath);
        error_log("FCM DEBUG: Render Secret Path readable? " . (is_readable($renderSecretPath) ? 'YES' : 'NO'));

        if ($credentialsPath) {
            // Method 1: Explicit Env Var Path
            if (!file_exists($credentialsPath)) {
                 throw new Exception("Env var GOOGLE_APPLICATION_CREDENTIALS points to '$credentialsPath', but file missing.");
            }
            $factory = $factory->withServiceAccount($credentialsPath);
        } elseif ($jsonCredentials) {
            // Method 2: JSON String in Env Var (High Priority & No Permission Issues)
            $data = json_decode($jsonCredentials, true);
            if (!$data) {
                throw new Exception("FIREBASE_CREDENTIA env var contains invalid JSON.");
            }
            // Sanitize private key: Aggressive Regex for \n, \\n, \\\n, etc.
            if (isset($data['private_key'])) {
                $data['private_key'] = preg_replace('/\\\\+n/', "\n", $data['private_key']);
            }
            
            // Verify key validity immediately
            $isValidKey = openssl_pkey_get_private($data['private_key']);
            
            // DEBUG: Log key format and validation result
            $keyStart = substr($data['private_key'], 0, 30);
            error_log("FCM DEBUG: Key starts with: " . $keyStart);
            error_log("FCM DEBUG: OpenSSL Key Valid? " . ($isValidKey ? 'YES' : 'NO - INVALID FORMAT'));
            
            $factory = $factory->withServiceAccount($data);
        } elseif (is_readable($renderSecretPath)) {
            // Method 3: Render Secret File (Standard)
            $factory = $factory->withServiceAccount($renderSecretPath);
        } elseif (file_exists($localPath)) {
            // Method 4: Local file (Dev)
            $factory = $factory->withServiceAccount($localPath);
        } else {
             throw new Exception("No credentials found! Set FIREBASE_CREDENTIA env var.");
        }

        $this->messaging = $factory->createMessaging();
    }

    public function sendNotification($token, $title, $body, $data = []) {
        try {
            $notification = Notification::create($title, $body);

            $message = CloudMessage::withTarget('token', $token)
                ->withNotification($notification)
                ->withData($data);

            $this->messaging->send($message);

            return ['success' => true];
        } catch (Exception $e) {
            error_log("FCM Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
