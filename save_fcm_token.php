<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/vendor/autoload.php';
require_once 'connect.php';

use Travel\Middleware\AuthMiddleware;
use Travel\Helpers\Response;
use Travel\Helpers\Validator;
use Dotenv\Dotenv;

// تحميل متغيرات البيئة
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// التحقق من JWT Token
$middleware = new AuthMiddleware();
$authenticatedUser = $middleware->validateToken();

// قراءة البيانات من JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$user_id   = $authenticatedUser['user_id']; // من التوكن
$fcm_token = isset($data['fcm_token']) ? trim($data['fcm_token']) : '';
$device    = isset($data['device_type']) ? trim($data['device_type']) : 'android';

// التحقق من البيانات
if (empty($fcm_token)) {
    Response::error('fcm_token مطلوب', 400);
}

// التحقق من نوع الجهاز
$allowedDevices = ['android', 'ios', 'web'];
if (!in_array($device, $allowedDevices)) {
    $device = 'android'; // القيمة الافتراضية
}

try {
    // حذف أي token قديم لنفس المستخدم + نفس الجهاز
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
        INSERT INTO user_device_tokens (user_id, fcm_token, device_type, created_at)
        VALUES (:uid, :token, :dev, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([
        ':uid'   => $user_id,
        ':token' => $fcm_token,
        ':dev'   => $device,
    ]);

    Response::success([
        'user_id' => $user_id,
        'device_type' => $device
    ], 'تم حفظ التوكن بنجاح');

} catch (PDOException $e) {
    error_log("FCM Token Save Error: " . $e->getMessage());
    Response::error('خطأ في حفظ التوكن', 500);
}

