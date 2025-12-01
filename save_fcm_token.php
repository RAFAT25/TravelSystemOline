<?php
// الاتصال بقاعدة البيانات:
require "connect.php"; // ملف الاتصال

$user_id   = intval($_POST['user_id'] ?? 0);
$fcm_token = $_POST['fcm_token'] ?? '';
$device    = $_POST['device_type'] ?? 'android';

if ($user_id <= 0 || !$fcm_token) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

// مثال: احذف أي token قديم لنفس المستخدم ثم أدخل الجديد
$stmt = $pdo->prepare("DELETE FROM user_device_tokens WHERE user_id = :uid AND device_type = :dev");
$stmt->execute([':uid' => $user_id, ':dev' => $device]);

$stmt = $pdo->prepare("
    INSERT INTO user_device_tokens (user_id, fcm_token, device_type)
    VALUES (:uid, :token, :dev)
");
$stmt->execute([
    ':uid'   => $user_id,
    ':token' => $fcm_token,
    ':dev'   => $device,
]);

echo json_encode(['status' => 'ok']);
