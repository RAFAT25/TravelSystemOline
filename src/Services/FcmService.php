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
        
        $factory = (new Factory());

        // Check if we have a path in GOOGLE_APPLICATION_CREDENTIALS
        if (getenv('GOOGLE_APPLICATION_CREDENTIALS')) {
            // Library detects this env var automatically, but explicit is fine too
            $factory = $factory->withServiceAccount(getenv('GOOGLE_APPLICATION_CREDENTIALS'));
        } 
        // Fallback: Check if we have the specific file in root credentials folder (for local)
        elseif (file_exists(__DIR__ . '/../../secrets/firebase_key.json')) {
            $factory = $factory->withServiceAccount(__DIR__ . '/../../secrets/firebase_key.json');
        } else {
             // throw new Exception("Firebase credentials not found. Set GOOGLE_APPLICATION_CREDENTIALS.");
             // For safety during dev if file missing, we might break.
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
