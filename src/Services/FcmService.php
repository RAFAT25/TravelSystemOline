<?php

namespace Travel\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Exception;

class FcmService {
    private $messaging;

    public function __construct() {
        // Option 1: Env var points to file path (Best for Render "Secret Files")
        // Option 2: Env var contains JSON content directly (FIREBASE_CREDENTIALS_JSON)
        
        $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        $jsonCredentials = getenv('FIREBASE_CREDENTIALS');
        $renderSecretPath = '/etc/secrets/firebase_key.json'; // Default Render path
        $localPath = __DIR__ . '/../../secrets/firebase_key.json';

        if ($credentialsPath) {
            // Method 1: Explicit Env Var Path
            if (!file_exists($credentialsPath)) {
                 throw new Exception("Env var GOOGLE_APPLICATION_CREDENTIALS points to '$credentialsPath', but file missing.");
            }
            $factory = $factory->withServiceAccount($credentialsPath);
        } elseif (file_exists($renderSecretPath)) {
            // Method 2: Render Secret File (Standard)
            $factory = $factory->withServiceAccount($renderSecretPath);
        } elseif ($jsonCredentials) {
            // Method 3: JSON String in Env Var
            $data = json_decode($jsonCredentials, true);
            if (!$data) {
                throw new Exception("FIREBASE_CREDENTIALS env var contains invalid JSON.");
            }
            // Sanitize private key
            if (isset($data['private_key'])) {
                $data['private_key'] = str_replace('\\n', "\n", $data['private_key']);
            }
            $factory = $factory->withServiceAccount($data);
        } elseif (file_exists($localPath)) {
            // Method 4: Local file (Dev)
            $factory = $factory->withServiceAccount($localPath);
        } else {
             throw new Exception("No credentials found! Add 'firebase_key.json' to Render Secret Files.");
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
