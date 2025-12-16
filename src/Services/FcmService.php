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

        $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
        $jsonCredentials = getenv('FIREBASE_CREDENTIALS');
        $localPath = __DIR__ . '/../../secrets/firebase_key.json';

        if ($credentialsPath) {
            // Method 1: Path to file (Render Secret File)
            if (!file_exists($credentialsPath)) {
                 throw new Exception("Env var GOOGLE_APPLICATION_CREDENTIALS is set to '$credentialsPath', but file does not exist.");
            }
            $factory = $factory->withServiceAccount($credentialsPath);
        } elseif ($jsonCredentials) {
            // Method 2: JSON String directly in Env Var
            $data = json_decode($jsonCredentials, true);
            if (!$data) {
                throw new Exception("FIREBASE_CREDENTIALS env var contains invalid JSON.");
            }
            
            // FIX: Handle newlines in private_key if they got escaped during copy-paste
            if (isset($data['private_key'])) {
                $data['private_key'] = str_replace('\\n', "\n", $data['private_key']);
            }

            $factory = $factory->withServiceAccount($data);
        } elseif (file_exists($localPath)) {
            // Method 3: Local file
            $factory = $factory->withServiceAccount($localPath);
        } else {
             throw new Exception("Firebase credentials not found! Set FIREBASE_CREDENTIALS env var, or GOOGLE_APPLICATION_CREDENTIALS path.");
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
