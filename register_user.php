<?php
// Only enable debug mode in development
$isDev = getenv('APP_ENV') === 'development';
ini_set('display_errors', $isDev ? 1 : 0);
ini_set('display_startup_errors', $isDev ? 1 : 0);
error_reporting($isDev ? E_ALL : 0);
header("Content-Type: application/json; charset=UTF-8");

include "connect.php";

// استقبال البيانات بصيغة JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// استقبال الحقول المطلوبة مع حماية
$full_name        = isset($data['full_name'])      ? trim($data['full_name'])      : '';
$email            = isset($data['email'])          ? trim($data['email'])          : '';
$password         = isset($data['password'])       ? $data['password']            : '';
$phone_number     = isset($data['phone_number'])   ? trim($data['phone_number'])   : '';
$user_type        = isset($data['user_type'])      ? $data['user_type']           : 'Customer';
$verificationCode = rand(100000, 999999);

// تحقق من الحقول
if(empty($full_name) || empty($email) || empty($password) || empty($phone_number)) {
    echo json_encode([
        "success" => false,
        "error" => "جميع الحقول مطلوبة"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// تحقق من وجود المستخدم مسبقًا حسب البريد أو الجوال
$checkStmt = $con->prepare("SELECT user_id FROM users WHERE email = :email OR phone_number = :phone_number");
$checkStmt->execute([
    ':email' => $email,
    ':phone_number' => $phone_number
]);
if ($checkStmt->rowCount() > 0) {
    echo json_encode([
        "success" => false,
        "error" => "البريد الإلكتروني أو الجوال مستخدم مسبقًا"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// تشفير كلمة المرور – تُخزن مشفرة فقط!
$password_hash = password_hash($password, PASSWORD_BCRYPT);

// إنشاء المستخدم الجديد
$query = $con->prepare("INSERT INTO users 
    (full_name, email, phone_number, password_hash, user_type, account_status, verification_code, created_at, updated_at)
    VALUES (:full_name, :email, :phone_number, :password_hash, :user_type, 'Unverified', :verification_code, NOW(), NOW())
    RETURNING user_id
");

$success = $query->execute([
    ':full_name' => $full_name,
    ':email' => $email,
    ':phone_number' => $phone_number,
    ':password_hash' => $password_hash,
    ':user_type' => $user_type,
    ':verification_code' => $verificationCode
]);

// إرجاع النتيجة بالـ JSON
if ($success) {
    $userId = $query->fetchColumn();
    echo json_encode([
        "success" => true,
        "user_id" => $userId,
        "user_name" => $full_name,
        "user_type" => $user_type,
        "account_status" => 'Unverified',
        "verification_code" => $verificationCode
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "success" => false,
        "error" => "حدث خطأ أثناء إنشاء الحساب!"
    ], JSON_UNESCAPED_UNICODE);
}
?>
