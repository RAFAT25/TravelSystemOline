<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Firebase\JWT\JWT;
use Travel\Helpers\Validator;
use Travel\Helpers\Response;

// تحميل متغيرات البيئة
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// الاتصال بقاعدة البيانات
include "connect.php";

// قراءة البيانات
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$email = isset($data['email']) ? trim($data['email']) : '';
$password = isset($data['password']) ? $data['password'] : '';

// التحقق من البيانات
Validator::clearErrors();
Validator::email($email);
Validator::required($password, 'password');

if (Validator::hasErrors()) {
    Response::validationError(Validator::getErrors());
}

// البحث عن المستخدم
$stmt = $con->prepare("SELECT user_id, full_name, email, password_hash, account_status FROM users WHERE email = :email");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
    Response::error('بيانات الدخول غير صحيحة', 401);
}

// التحقق من حالة الحساب
if ($user['account_status'] === 'Suspended') {
    Response::error('الحساب موقوف، تواصل مع الدعم', 403);
}

// إنشاء JWT Token
$secret_key = getenv('JWT_SECRET');
if (empty($secret_key)) {
    if (getenv('APP_ENV') === 'development') {
        $secret_key = 'dev_secret_key_for_local_testing_only_32chars!';
    } else {
        Response::error('خطأ في إعدادات السيرفر', 500);
    }
}

$issuedAt = time();
$expirationTime = $issuedAt + (60 * 60 * 24); // 24 ساعة

$payload = [
    'iat' => $issuedAt,
    'exp' => $expirationTime,
    'data' => [
        'user_id' => $user['user_id'],
        'email' => $user['email'],
        'name' => $user['full_name']
    ]
];

$jwt = JWT::encode($payload, $secret_key, 'HS256');

// إرجاع النتيجة
Response::success([
    'user_id' => $user['user_id'],
    'user_name' => $user['full_name'],
    'userEmail' => $user['email'],
    'account_status' => $user['account_status'],
    'token' => $jwt
]);

