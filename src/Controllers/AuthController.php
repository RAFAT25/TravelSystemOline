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
        
        // JWT Secret - يستخدم متغير البيئة أو قيمة افتراضية
        $this->secret_key = getenv('JWT_SECRET');
        
        if (empty($this->secret_key)) {
            // قيمة افتراضية آمنة للتطوير والإنتاج
            $this->secret_key = 'WQ3KUIBxd7gGsyNE6PDf5wZRctuMoShqFmXrAvenlCVkp1zJ9H2j4YTa8iLb0O';
        }
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

        $stmt = $this->conn->prepare("SELECT user_id, full_name, email, password_hash, user_type FROM users WHERE email = :email");
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
                    'name' => $user['full_name'],
                    'user_type' => $user['user_type']
                ]
            ];

            $jwt = JWT::encode($payload, $this->secret_key, 'HS256');

            echo json_encode([
                "success"   => true,
                "user_id"   => $user['user_id'],
                "user_name" => $user['full_name'],
                "userEmail" => $user['email'],
                "user_type" => $user['user_type'],
                "token"     => $jwt
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                "success" => false,
                "error"   => "Invalid credentials"
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function register() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $full_name    = isset($data['full_name'])    ? trim($data['full_name'])    : '';
        $email        = isset($data['email'])        ? trim($data['email'])        : '';
        $password     = isset($data['password'])     ? $data['password']          : '';
        $phone_number = isset($data['phone_number']) ? trim($data['phone_number']) : '';
        $user_type    = isset($data['user_type'])    ? $data['user_type']         : 'Customer';
        
        if (empty($full_name) || empty($email) || empty($password) || empty($phone_number)) {
            echo json_encode([
                "success" => false,
                "error"   => "All fields are required"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Check if user already exists
        $checkStmt = $this->conn->prepare("SELECT user_id FROM users WHERE email = :email OR phone_number = :phone_number");
        $checkStmt->execute([
            ':email' => $email,
            ':phone_number' => $phone_number
        ]);

        if ($checkStmt->rowCount() > 0) {
            echo json_encode([
                "success" => false,
                "error"   => "Email or phone number already in use"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $verificationCode = rand(100000, 999999);

        $query = $this->conn->prepare("INSERT INTO users 
            (full_name, email, phone_number, password_hash, user_type, account_status, verification_code, created_at, updated_at)
            VALUES (:full_name, :email, :phone_number, :password_hash, :user_type, 'Unverified', :verification_code, NOW(), NOW())
            RETURNING user_id
        ");

        $success = $query->execute([
            ':full_name'        => $full_name,
            ':email'            => $email,
            ':phone_number'     => $phone_number,
            ':password_hash'    => $password_hash,
            ':user_type'        => $user_type,
            ':verification_code' => $verificationCode
        ]);

        if ($success) {
            $userId = $query->fetchColumn();
            echo json_encode([
                "success"           => true,
                "user_id"           => $userId,
                "user_name"         => $full_name,
                "user_type"         => $user_type,
                "account_status"    => 'Unverified',
                "verification_code" => $verificationCode
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                "success" => false,
                "error"   => "Error creating account"
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}
