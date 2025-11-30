<?php
require_once __DIR__ . '/../config/database.php';

// استقبال البيانات
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$email = isset($data['email']) ? $data['email'] : '';
$code  = isset($data['code']) ? $data['code'] : '';

// تحقق من الحقول الأساسية
if (empty($email) || empty($code)) {
    echo json_encode([
        "success" => false,
        "error"   => "كل الحقول مطلوبة"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// الاستعلام عن المستخدم حسب البريد وكود التحقق
$stmt = $con->prepare("SELECT user_id, account_status FROM users WHERE email=? AND `verification code`=?");
$stmt->execute([$email, $code]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    // تحقق الحساب إذا لم يكن مفعل بالفعل
    if ($user['account_status'] != 'Verified') {
        $up = $con->prepare("UPDATE users SET account_status='Verified' WHERE user_id=?");
        $up->execute([$user['user_id']]);
    }
    echo json_encode([
        "success" => true,
        "message" => "تم تفعيل الحساب بنجاح"
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "success" => false,
        "error"   => "رمز التحقق خاطئ أو البريد غير صحيح"
    ], JSON_UNESCAPED_UNICODE);
}
?>
