<?php

namespace Travel\Controllers;

use Travel\Helpers\Response;
use Travel\Services\FcmService;
use Travel\Services\Whapi;

class NotificationController {
    
    public function sendTest() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $token = $data['fcm_token'] ?? '';
        $title = $data['title'] ?? 'Test Notification';
        $body = $data['body'] ?? 'This is a test message from your PHP Backend';

        if (empty($token)) {
            Response::error("fcm_token is required", 400);
            return;
        }

        $fcm = new FcmService();
        $result = $fcm->sendNotification($token, $title, $body);

        Response::send($result);
    }
    public function send() {
        header('Content-Type: application/json; charset=utf-8');
        
        // Middleware handles authentication
        $middleware = new \Travel\Middleware\AuthMiddleware();
        $authenticatedUser = $middleware->validateToken();

        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);

        $target_user_id = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        $title = isset($data['title']) ? trim($data['title']) : '';
        $body  = isset($data['body']) ? trim($data['body']) : '';
        $extra_data = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];

        if ($target_user_id <= 0 || empty($title) || empty($body)) {
            Response::error("user_id, title and body are required", 400);
            return;
        }

        try {
            $db = new \Travel\Config\Database();
            $conn = $db->connect();

            $stmt = $conn->prepare("
                SELECT fcm_token
                FROM user_device_tokens
                WHERE user_id = :uid
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([':uid' => $target_user_id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row || empty($row['fcm_token'])) {
                Response::error("No token found for this user", 404);
                return;
            }

            $targetToken = $row['fcm_token'];
            $fcmService = new FcmService();
            
            $notificationData = array_merge([
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                'sender_id' => isset($authenticatedUser['user_id']) ? (string)$authenticatedUser['user_id'] : '0',
            ], $extra_data);

            $result = $fcmService->sendNotification($targetToken, $title, $body, $notificationData);

            Response::send($result);

        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function saveToken() {
        header('Content-Type: application/json; charset=utf-8');
        
        $middleware = new \Travel\Middleware\AuthMiddleware();
        $authenticatedUser = $middleware->validateToken();

        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $user_id   = $authenticatedUser['user_id'];
        $fcm_token = isset($data['fcm_token']) ? trim($data['fcm_token']) : '';
        $device    = isset($data['device_type']) ? trim($data['device_type']) : 'android';

        if (empty($fcm_token)) {
            Response::error("fcm_token is required", 400);
            return;
        }

        $allowedDevices = ['android', 'ios', 'web'];
        if (!in_array($device, $allowedDevices)) {
            $device = 'android';
        }

        try {
            $db = new \Travel\Config\Database();
            $conn = $db->connect();

            $stmt = $conn->prepare("DELETE FROM user_device_tokens WHERE user_id = :uid AND device_type = :dev");
            $stmt->execute([':uid' => $user_id, ':dev' => $device]);

            $stmt = $conn->prepare("INSERT INTO user_device_tokens (user_id, fcm_token, device_type, created_at) VALUES (:uid, :token, :dev, CURRENT_TIMESTAMP)");
            $stmt->execute([':uid' => $user_id, ':token' => $fcm_token, ':dev' => $device]);

            Response::success([], "Token saved successfully");

        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function sendWhatsApp() {
        header('Content-Type: application/json; charset=utf-8');

        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);

        $to   = $data["to"]   ?? null;
        $body = $data["body"] ?? null;

        if (!$to || !$body) {
            Response::error("Send 'to' and 'body' in JSON", 400);
            return;
        }

        try {
            $result = Whapi::sendText($to, $body);
            Response::send($result);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}
