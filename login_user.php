<?php
include "connect.php";

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$email = isset($data['email']) ? $data['email'] : '';
$password = isset($data['password']) ? $data['password'] : '';

// تحقق من البيانات
if (empty($email) || empty($password)) {
    echo json_encode([
        "success" => false,
        "error"   => "البريد وكلمة المرور مطلوبان"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// استعلام المستخدم عبر البريد فقط
$stmt = $con->prepare("SELECT user_id, full_name, password_hash FROM users WHERE email = :email");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user && password_verify($password, $user['password_hash'])) {
    // إنشاء توكن وهمي (للتجربة فقط)
    $token = md5($user['user_id'] . time());

    echo json_encode([
        "success"   => true,
        "user_id"   => $user['user_id'],
        "user_name" => $user['full_name'],
        "userEmail" => $user['email'],
        "token"     => $token
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "success" => false,
        "error"   => "بيانات الدخول غير صحيحة"
    ], JSON_UNESCAPED_UNICODE);
}
?>
