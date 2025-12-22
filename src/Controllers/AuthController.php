<?php

namespace Travel\Controllers;

use Travel\Config\Database;
use Travel\Helpers\Response;
use Travel\Services\Whapi;
use Travel\Services\EmailService;
use Firebase\JWT\JWT;
use PDO;
use RuntimeException;

class AuthController {
    private $conn;
    private $secret_key;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
        
        // JWT Secret - Uses environment variable only
        $this->secret_key = getenv('JWT_SECRET');
        
        if (empty($this->secret_key)) {
            // CRITICAL: Block operation if secret is missing
            throw new RuntimeException("CRITICAL: JWT_SECRET environment variable is missing.");
        }
    }

    public function login() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $identifier = $data['identifier'] ?? $data['email'] ?? $data['phone'] ?? $data['phone_number'] ?? '';
        $password   = $data['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            Response::error("Email/Phone and password are required", 400);
            return;
        }

        $stmt = $this->conn->prepare("
            SELECT user_id, full_name, email, phone_number, password_hash, user_type 
            FROM users 
            WHERE email = :id OR phone_number = :id
        ");
        $stmt->execute([':id' => $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // ... (payload logic remains same)
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

            Response::success([
                "user_id"      => $user['user_id'],
                "user_name"    => $user['full_name'],
                "userEmail"    => $user['email'],
                "phone_number" => $user['phone_number'],
                "user_type"    => $user['user_type'],
                "token"        => $jwt
            ]);
        } else {
            Response::error("Invalid credentials", 401);
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
            Response::error("All fields are required", 400);
            return;
        }

        // Check if user already exists
        $checkStmt = $this->conn->prepare("SELECT user_id FROM users WHERE email = :email OR phone_number = :phone_number");
        $checkStmt->execute([
            ':email' => $email,
            ':phone_number' => $phone_number
        ]);

        if ($checkStmt->rowCount() > 0) {
            Response::error("Email or phone number already in use", 409);
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

            // Send Verification Code via WhatsApp
            try {
                $msg = "كود التحقق الخاص بك هو: $verificationCode";
                Whapi::sendText($phone_number, $msg);
            } catch (\Exception $e) {
                // Log error but don't fail registration
            }

            // Send Verification Code via Email
            try {
                EmailService::sendVerificationCode($email, $verificationCode, $full_name);
            } catch (\Exception $e) {
                // Log error but don't fail registration
            }

            Response::success([
                "user_id"           => $userId,
                "user_name"         => $full_name,
                "user_type"         => $user_type,
                "account_status"    => 'Unverified',
                "verification_code" => $verificationCode
            ]);
        } else {
            Response::error("Error creating account", 500);
        }
    }

    public function updateProfile($actor = null) {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        // Get user_id from token (actor) primarily for security
        $userId = ($actor && isset($actor['user_id'])) ? (int)$actor['user_id'] : 0;
        
        // Fallback for legacy support if needed, though index.php now enforces token
        if ($userId <= 0) {
            $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        }

        $phone           = trim($data['phone'] ?? '');
        $currentPassword = trim($data['current_password'] ?? '');
        $newPassword     = trim($data['new_password'] ?? '');

        if ($userId <= 0) {
            Response::error("Invalid user_id", 400);
            return;
        }

        // Get current user
        $stmt = $this->conn->prepare("SELECT user_id, phone_number, password_hash FROM users WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            Response::notFound("User not found");
            return;
        }

        $fieldsToUpdate = [];
        $params = [':id' => $userId];

        if ($phone !== '') {
            $fieldsToUpdate[] = "phone_number = :phone";
            $params[':phone'] = $phone;
        }

        if ($newPassword !== '') {
            if ($currentPassword === '') {
                Response::error("Current password is required to set a new one", 400);
                return;
            }

            if (!password_verify($currentPassword, $user['password_hash'])) {
                Response::error("Current password is incorrect", 401);
                return;
            }

            $params[':pass'] = password_hash($newPassword, PASSWORD_BCRYPT);
            $fieldsToUpdate[] = "password_hash = :pass";
        }

        if (empty($fieldsToUpdate)) {
            Response::error("No data provided for update", 400);
            return;
        }

        $setClause = implode(", ", $fieldsToUpdate);
        $up = $this->conn->prepare("UPDATE users SET $setClause, updated_at = NOW() WHERE user_id = :id");
        $up->execute($params);

        Response::success([
            "user_phone" => $phone !== '' ? $phone : $user['phone_number']
        ], "Profile updated successfully");
    }

    public function forgotPassword() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $identifier = $data['identifier'] ?? $data['email'] ?? $data['phone'] ?? $data['phone_number'] ?? '';

        if (empty($identifier)) {
            Response::error("Email or Phone is required", 400);
            return;
        }

        $stmt = $this->conn->prepare("SELECT user_id, email, phone_number FROM users WHERE email = :id OR phone_number = :id");
        $stmt->execute([':id' => $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            Response::notFound("User not found");
            return;
        }

        $resetCode = rand(100000, 999999);
        $stmt = $this->conn->prepare("UPDATE users SET verification_code = :code, updated_at = NOW() WHERE user_id = :id");
        $stmt->execute([':code' => $resetCode, ':id' => $user['user_id']]);

        // Send reset code via WhatsApp if phone is available
        $targetPhone = $user['phone_number'] ?? '';
        if ($targetPhone) {
            try {
                $msg = "كود إعادة تعيين كلمة المرور الخاص بك هو: $resetCode. لا تشارك هذا الكود مع أحد.";
                Whapi::sendText($targetPhone, $msg);
            } catch (\Exception $e) {
                // Log and maybe handle error
            }
        }

        // Send reset code via Email if available
        $targetEmail = $user['email'] ?? '';
        if ($targetEmail) {
            try {
                EmailService::sendVerificationCode($targetEmail, $resetCode, $user['full_name'] ?? 'User');
            } catch (\Exception $e) {
                // Log and maybe handle error
            }
        }

        Response::success([
            "reset_code" => $resetCode
        ], "Reset code sent to your phone/email");
    }

    public function verifyResetCode() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $identifier = $data['identifier'] ?? $data['email'] ?? $data['phone'] ?? $data['phone_number'] ?? '';
        $code = $data['code'] ?? '';

        if (empty($identifier) || empty($code)) {
            Response::error("Email/Phone and code are required", 400);
            return;
        }

        $stmt = $this->conn->prepare("SELECT user_id FROM users WHERE (email = :id OR phone_number = :id) AND verification_code = :code");
        $stmt->execute([':id' => $identifier, ':code' => $code]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            Response::error("Invalid code or email", 401);
            return;
        }

        Response::success([], "Code verified successfully");
    }

    public function resetPassword() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $identifier = $data['identifier'] ?? $data['email'] ?? $data['phone'] ?? $data['phone_number'] ?? '';
        $code = $data['code'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (empty($identifier) || empty($code) || empty($newPassword)) {
            Response::error("Email/Phone, code and new password are required", 400);
            return;
        }

        $stmt = $this->conn->prepare("SELECT user_id FROM users WHERE (email = :id OR phone_number = :id) AND verification_code = :code");
        $stmt->execute([':id' => $identifier, ':code' => $code]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            Response::error("Invalid code or email", 401);
            return;
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $this->conn->prepare("UPDATE users SET password_hash = :pass, verification_code = NULL, updated_at = NOW() WHERE user_id = :id");
        $stmt->execute([':pass' => $newHash, ':id' => $user['user_id']]);

        Response::success([], "Password reset successfully");
    }
}
