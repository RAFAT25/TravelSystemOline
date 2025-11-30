<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$con = getConnection();

// قراءة JSON
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!is_array($data)) {
    echo json_encode([
        "success" => false,
        "error"   => "تنسيق البيانات غير صحيح"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$email = isset($data['email']) ? trim($data['email']) : '';

if ($email === '') {
    echo json_encode([
        "success" => false,
        "error"   => "يجب إدخال البريد الإلكتروني"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// التحقق من وجود المستخدم
$stmt = $con->prepare("SELECT user_id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode([
        "success" => false,
        "error"   => "لا يوجد حساب مسجل بهذا البريد"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// توليد كود 6 أرقام
$code = random_int(100000, 999999);

// تخزين الكود في users.verification_code
$up = $con->prepare("UPDATE users SET verification_code = :code WHERE user_id = :id");
$up->execute([
    ':code' => $code,
    ':id'   => $user['user_id'],
]);

// في الإنتاج: أرسل الكود عبر بريد إلكتروني بدلاً من إرجاعه
echo json_encode([
    "success"           => true,
    "message"           => "تم إرسال كود الاستعادة إلى بريدك",
    "verification_code" => $code   // لأغراض التجربة فقط
], JSON_UNESCAPED_UNICODE);
