<?php
require_once 'connect.php';  // كودك الأصلي بدون تغيير

header('Content-Type: application/json');

$user_id = $_POST['user_id'] ?? null;
$fcm_token = $_POST['fcm_token'] ?? null;

if (!$user_id || !$fcm_token) {
    echo json_encode(['success' => false, 'error' => 'بيانات ناقصة']);
    exit;
}

// استخدام $con من db_connection.php مباشرة
$stmt = $con->prepare("
    INSERT INTO user_tokens (user_id, fcm_token) VALUES (?, ?) 
    ON CONFLICT (user_id) DO UPDATE SET 
    fcm_token = ?, updated_at = NOW()
");
$stmt->execute([$user_id, $fcm_token, $fcm_token]);

echo json_encode(['success' => true]);
?>
