<?php

namespace Travel\Controllers;

use Travel\Services\FcmService;

class NotificationController {
    
    public function sendTest() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $token = $data['fcm_token'] ?? '';
        $title = $data['title'] ?? 'Test Notification';
        $body = $data['body'] ?? 'This is a test message from your PHP Backend';

        if (empty($token)) {
            echo json_encode([
                "success" => false,
                "error" => "fcm_token is required"
            ]);
            return;
        }

        $fcm = new FcmService();
        $result = $fcm->sendNotification($token, $title, $body);

        echo json_encode($result);
    }
}
