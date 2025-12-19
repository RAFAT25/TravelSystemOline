<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/vendor/autoload.php';
require_once 'connect.php';

use Travel\Middleware\AuthMiddleware;
use Travel\Services\FcmService;
use Travel\Helpers\Response;
use Dotenv\Dotenv;

// تحميل متغيرات البيئة
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// التحقق من JWT Token
$middleware = new AuthMiddleware();
$authenticatedUser = $middleware->validateToken();

// قراءة البيانات
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

$target_user_id = isset($data['user_id']) ? (int)$data['user_id'] : 0;
$title = isset($data['title']) ? trim($data['title']) : '';
$body  = isset($data['body']) ? trim($data['body']) : '';
$extra_data = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];

// التحقق من البيانات
if ($target_user_id <= 0) {
    Response::error('user_id مطلوب', 400);
}

if (empty($title) || empty($body)) {
    Response::error('title و body مطلوبان', 400);
}

try {
    // 1) جلب آخر token للمستخدم المستهدف
    $stmt = $con->prepare("
        SELECT fcm_token
        FROM user_device_tokens
        WHERE user_id = :uid
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([':uid' => $target_user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['fcm_token'])) {
        Response::error('لا يوجد توكن لهذا المستخدم', 404);
    }

    $targetToken = $row['fcm_token'];

    // 2) إرسال الإشعار باستخدام FcmService
    $fcmService = new FcmService();
    
    // إضافة بيانات إضافية
    $notificationData = array_merge([
        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        'sender_id' => (string)$authenticatedUser['user_id'],
    ], $extra_data);

    $result = $fcmService->sendNotification($targetToken, $title, $body, $notificationData);

    if ($result['success']) {
        Response::success([
            'target_user_id' => $target_user_id,
            'title' => $title,
            'body' => $body
        ], 'تم إرسال الإشعار بنجاح');
    } else {
        Response::error('فشل إرسال الإشعار: ' . ($result['error'] ?? 'خطأ غير معروف'), 500);
    }

} catch (Exception $e) {
    error_log("Send Notification Error: " . $e->getMessage());
    Response::error('خطأ في إرسال الإشعار', 500);
}

