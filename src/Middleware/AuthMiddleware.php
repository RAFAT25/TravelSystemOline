<?php

namespace Travel\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class AuthMiddleware {
    private $secret_key;

    public function __construct() {
        // JWT Secret - يستخدم متغير البيئة أو قيمة افتراضية
        $this->secret_key = getenv('JWT_SECRET');
        
        if (empty($this->secret_key)) {
            // قيمة افتراضية آمنة للتطوير والإنتاج
            $this->secret_key = 'WQ3KUIBxd7gGsyNE6PDf5wZRctuMoShqFmXrAvenlCVkp1zJ9H2j4YTa8iLb0O';
        }
    }

    public function validateToken() {
        $headers = getallheaders();
        $authHeader = null;

        // Check for Authorization header (case insensitive)
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $authHeader = $value;
                break;
            }
        }

        if (!$authHeader) {
            $this->sendError("Authorization header not found");
        }

        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $this->sendError("Token format invalid (Bearer <token>)");
        }

        $jwt = $matches[1];

        try {
            $decoded = JWT::decode($jwt, new Key($this->secret_key, 'HS256'));
            
            // Convert object to array
            $claims = (array) $decoded;

            // If 'data' exists, merge its content into the main array
            // This handles { "data": { "user_id": 1 } } AND { "user_id": 1 } formats
            if (isset($claims['data'])) {
                $data = (array) $claims['data'];
                $claims = array_merge($claims, $data);
            }
            
            return $claims;
        } catch (Exception $e) {
            // For debugging: show what was received (first 10 chars)
            $debugToken = substr($jwt, 0, 10) . "...";
            $this->sendError("Access denied: " . $e->getMessage() . " (Received: $debugToken)");
        }
    }

    private function sendError($message) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "error" => $message
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
