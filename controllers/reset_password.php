<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$con = getConnection();

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!is_array($data)) {
    echo json_encode([
        "success" => false,
        "error"   => "تنسيق البيانات غير صحيح"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$email       = isset($data['email'])        ? trim($data['email'])        : '';
$newPassword = isset($data['new_password']) ? $data['new_password']       : '';

if ($email === '' || $newPassword === '') {
    echo json_encode([
        "success" => false,
        "error"   => "كل الحقول مطلوبة"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// التحقق من أن المستخدم موجود
$stmt = $con->prepare("SELECT user_id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode([
        "success" => false,
        "error"   => "لا يوجد حساب مرتبط بهذا البريد"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// تشفير كلمة المرور
$hashed = password_hash($newPassword, PASSWORD_BCRYPT);

// تحديث كلمة المرور وتصفير كود التحقق
$up = $con->prepare(
    "UPDATE users 
     SET password = :pass, verification_code = NULL 
     WHERE user_id = :id"
);
$up->execute([
    ':pass' => $hashed,
    ':id'   => $user['user_id'],
]);

echo json_encode([
    "success" => true,
    "message" => "تم تحديث كلمة المرور بنجاح"
], JSON_UNESCAPED_UNICODE);
