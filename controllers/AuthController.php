<?php
// controllers/AuthController.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/BrevoMailer.php';

class AuthController
{
    private PDO $con;
    private BrevoMailer $mailer;

    public function __construct()
    {
        $this->con    = getConnection();
        $this->mailer = new BrevoMailer();
    }

    public function register(array $data): array
    {
        $full_name    = isset($data['full_name'])    ? trim($data['full_name'])    : '';
        $email        = isset($data['email'])        ? trim($data['email'])        : '';
        $password     = isset($data['password'])     ? $data['password']           : '';
        $phone_number = isset($data['phone_number']) ? trim($data['phone_number']) : '';
        $user_type    = isset($data['user_type'])    ? $data['user_type']          : 'Customer';
        $verificationCode = rand(100000, 999999);

        // تحقق من الحقول
        if (empty($full_name) || empty($email) || empty($password) || empty($phone_number)) {
            return [
                "success" => false,
                "error"   => "جميع الحقول مطلوبة"
            ];
        }

        // تحقق من وجود المستخدم مسبقًا
        $checkStmt = $this->con->prepare("SELECT user_id FROM users WHERE email = :email OR phone_number = :phone_number");
        $checkStmt->execute([
            ':email'        => $email,
            ':phone_number' => $phone_number
        ]);
        if ($checkStmt->rowCount() > 0) {
            return [
                "success" => false,
                "error"   => "البريد الإلكتروني أو الجوال مستخدم مسبقًا"
            ];
        }

        // تشفير كلمة المرور
        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        // إنشاء المستخدم الجديد
        $query = $this->con->prepare("INSERT INTO users 
            (full_name, email, phone_number, password_hash, user_type, account_status, verification_code, created_at, updated_at)
            VALUES (:full_name, :email, :phone_number, :password_hash, :user_type, 'Unverified', :verification_code, NOW(), NOW())
            RETURNING user_id
        ");

        $success = $query->execute([
            ':full_name'         => $full_name,
            ':email'             => $email,
            ':phone_number'      => $phone_number,
            ':password_hash'     => $password_hash,
            ':user_type'         => $user_type,
            ':verification_code' => $verificationCode
        ]);

        if (!$success) {
            return [
                "success" => false,
                "error"   => "حدث خطأ أثناء إنشاء الحساب!"
            ];
        }

        $userId = $query->fetchColumn();

        // إرسال إيميل التحقق
        $emailResult = $this->mailer->sendVerificationEmail($email, $full_name, $verificationCode); // [web:81][web:88]

        return [
            "success"           => true,
            "user_id"           => $userId,
            "user_name"         => $full_name,
            "user_type"         => $user_type,
            "account_status"    => 'Unverified',
            "verification_code" => $verificationCode,
            "email_sent"        => $emailResult['success'],
            "email_response"    => $emailResult['response']
        ];
    }
}
