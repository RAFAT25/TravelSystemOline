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

    // -------- دالة التسجيل الموجودة عندك --------
    public function register(array $data): array
    {
        $full_name        = isset($data['full_name'])    ? trim($data['full_name'])    : '';
        $email            = isset($data['email'])        ? trim($data['email'])        : '';
        $password         = isset($data['password'])     ? $data['password']           : '';
        $phone_number     = isset($data['phone_number']) ? trim($data['phone_number']) : '';
        $user_type        = isset($data['user_type'])    ? $data['user_type']          : 'Customer';
        $verificationCode = rand(100000, 999999);

        if (empty($full_name) || empty($email) || empty($password) || empty($phone_number)) {
            return [
                "success" => false,
                "error"   => "جميع الحقول مطلوبة"
            ];
        }

        $checkStmt = $this->con->prepare(
            "SELECT user_id FROM users WHERE email = :email OR phone_number = :phone_number"
        );
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

        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        $query = $this->con->prepare(
            "INSERT INTO users 
                (full_name, email, phone_number, password_hash, user_type, account_status, verification_code, created_at, updated_at)
             VALUES (:full_name, :email, :phone_number, :password_hash, :user_type, 'Unverified', :verification_code, NOW(), NOW())
             RETURNING user_id"
        );

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

        $emailResult = $this->mailer->sendVerificationEmail($email, $full_name, $verificationCode);

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

    // -------- 1) نسيان كلمة المرور --------
    public function forgotPassword(array $data): array
    {
        $email = isset($data['email']) ? trim($data['email']) : '';

        if ($email === '') {
            return [
                "success" => false,
                "error"   => "يجب إدخال البريد الإلكتروني"
            ];
        }

        $stmt = $this->con->prepare(
            "SELECT user_id, full_name FROM users WHERE email = :email"
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [
                "success" => false,
                "error"   => "لا يوجد حساب مسجل بهذا البريد"
            ];
        }

        $verificationCode = rand(100000, 999999);

        $up = $this->con->prepare(
            "UPDATE users SET verification_code = :code, updated_at = NOW() WHERE user_id = :id"
        );
        $up->execute([
            ':code' => $verificationCode,
            ':id'   => $user['user_id'],
        ]);

        $emailResult = $this->mailer->sendVerificationEmail(
            $email,
            $user['full_name'],
            $verificationCode
        );

        return [
            "success"           => true,
            "message"           => "تم إرسال كود الاستعادة إلى بريدك الإلكتروني",
            "verification_code" => $verificationCode,   // لأغراض الاختبار فقط
            "email_sent"        => $emailResult['success'],
            "email_response"    => $emailResult['response'],
        ];
    }

    // -------- 2) التحقق من كود الاستعادة --------
    public function verifyResetCode(array $data): array
    {
        $email = isset($data['email']) ? trim($data['email']) : '';
        $code  = isset($data['code'])  ? trim($data['code'])  : '';

        if ($email === '' || $code === '') {
            return [
                "success" => false,
                "error"   => "كل الحقول مطلوبة"
            ];
        }

        $stmt = $this->con->prepare(
            "SELECT user_id FROM users WHERE email = :email AND verification_code = :code"
        );
        $stmt->execute([
            ':email' => $email,
            ':code'  => $code,
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [
                "success" => false,
                "error"   => "رمز التحقق خاطئ أو البريد غير صحيح"
            ];
        }

        return [
            "success" => true,
            "message" => "الكود صحيح، يمكنك تعيين كلمة مرور جديدة"
        ];
    }

    // -------- 3) تعيين كلمة المرور الجديدة --------
    public function resetPassword(array $data): array
    {
        $email       = isset($data['email'])        ? trim($data['email'])  : '';
        $newPassword = isset($data['new_password']) ? $data['new_password'] : '';

        if ($email === '' || $newPassword === '') {
            return [
                "success" => false,
                "error"   => "كل الحقول مطلوبة"
            ];
        }

        $stmt = $this->con->prepare(
            "SELECT user_id FROM users WHERE email = :email"
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return [
                "success" => false,
                "error"   => "لا يوجد حساب مرتبط بهذا البريد"
            ];
        }

        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);

        $up = $this->con->prepare(
            "UPDATE users 
             SET password_hash = :pass, verification_code = NULL, updated_at = NOW()
             WHERE user_id = :id"
        );
        $up->execute([
            ':pass' => $hashed,
            ':id'   => $user['user_id'],
        ]);

        return [
            "success" => true,
            "message" => "تم تحديث كلمة المرور بنجاح"
        ];
    }
}
