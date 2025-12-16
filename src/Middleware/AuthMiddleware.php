<?php

namespace Travel\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class AuthMiddleware {
    private $secret_key;

    public function __construct() {
        // Use a secure key from env or default for dev (warn usage)
        $this->secret_key = getenv('JWT_SECRET') ?: 'default_secret_key_CHANGE_ME';
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
            return (array) $decoded->data; // Return user data
        } catch (Exception $e) {
            $this->sendError("Access denied: " . $e->getMessage());
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
