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

$email = isset($data['email']) ? trim($data['email']) : '';
$code  = isset($data['code'])  ? trim($data['code'])  : '';

if ($email === '' || $code === '') {
    echo json_encode([
        "success" => false,
        "error"   => "كل الحقول مطلوبة"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$stmt = $con->prepare(
    "SELECT user_id FROM users WHERE email = ? AND verification_code = ?"
);
$stmt->execute([$email, $code]);
$user = $stmt->fetch();

if ($user) {
    echo json_encode([
        "success" => true,
        "message" => "الكود صحيح، يمكنك تعيين كلمة مرور جديدة"
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "success" => false,
        "error"   => "رمز التحقق خاطئ أو البريد غير صحيح"
    ], JSON_UNESCAPED_UNICODE);
}
