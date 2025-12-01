<?php
header('Content-Type: application/json; charset=utf-8');

require 'connect.php'; // الملف الذي فيه $con (اتصال PDO)

// قراءة البيانات من POST
$user_id   = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$fcm_token = $_POST['fcm_token'] ?? '';
$device    = $_POST['device_type'] ?? 'android';

if ($user_id <= 0 || $fcm_token === '') {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Missing user_id or fcm_token',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // حذف أي token قديم لنفس المستخدم + نفس الجهاز (اختياري)
    $stmt = $con->prepare("
        DELETE FROM user_device_tokens
        WHERE user_id = :uid AND device_type = :dev
    ");
    $stmt->execute([
        ':uid' => $user_id,
        ':dev' => $device,
    ]);

    // إدخال token الجديد
    $stmt = $con->prepare("
        INSERT INTO user_device_tokens (user_id, fcm_token, device_type)
        VALUES (:uid, :token, :dev)
    ");
    $stmt->execute([
        ':uid'   => $user_id,
        ':token' => $fcm_token,
        ':dev'   => $device,
    ]);

    echo json_encode([
        'status'  => 'ok',
        'message' => 'Token saved',
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'DB error: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
