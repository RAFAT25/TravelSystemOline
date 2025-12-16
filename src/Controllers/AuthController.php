<?php

namespace Travel\Controllers;

use Travel\Config\Database;
use Firebase\JWT\JWT;
use PDO;

class AuthController {
    private $conn;
    private $secret_key;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
        $this->secret_key = getenv('JWT_SECRET') ?: 'default_secret_key_CHANGE_ME';
    }

    public function login() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            echo json_encode([
                "success" => false,
                "error"   => "Email and password are required"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $stmt = $this->conn->prepare("SELECT user_id, full_name, email, password_hash FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            
            $issuedAt = time();
            $expirationTime = $issuedAt + (60 * 60 * 24); // 1 day validity
            
            $payload = [
                'iat' => $issuedAt,
                'exp' => $expirationTime,
                'data' => [
                    'user_id' => $user['user_id'],
                    'email' => $user['email'],
                    'name' => $user['full_name']
                ]
            ];

            $jwt = JWT::encode($payload, $this->secret_key, 'HS256');

            echo json_encode([
                "success"   => true,
                "user_id"   => $user['user_id'],
                "user_name" => $user['full_name'],
                "userEmail" => $user['email'],
                "token"     => $jwt
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                "success" => false,
                "error"   => "Invalid credentials"
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}
