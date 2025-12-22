<?php

namespace Travel\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Travel\Helpers\Response;
use Exception;
use RuntimeException;

class AuthMiddleware {
    private $secret_key;

    public function __construct() {
        // JWT Secret - Uses environment variable only
        $this->secret_key = getenv('JWT_SECRET');
        
        if (empty($this->secret_key)) {
            // CRITICAL: Block operation if secret is missing to prevent insecure defaults
            throw new RuntimeException("CRITICAL: JWT_SECRET environment variable is missing.");
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
        Response::unauthorized($message);
    }
}
