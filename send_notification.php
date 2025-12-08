<?php
header('Content-Type: application/json; charset=utf-8');

require 'connect.php'; // يحتوي على $con (PDO PostgreSQL/MySQL)

// إعدادات Firebase
$serviceAccountPath = __DIR__ . '/config/unified-adviser-408114-firebase-adminsdk-hcjoe-dea9fa958b.json';

$projectId = 'unified-adviser-408114'; // Project ID من Firebase Console

// 0) قراءة JSON من جسم الطلب
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

$user_id = isset($data['user_id']) ? (int)$data['user_id'] : 0;
$title   = isset($data['title'])   ? trim($data['title'])   : 'Test title';
$body    = isset($data['body'])    ? trim($data['body'])    : 'Test body';

if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Missing user_id'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) جلب آخر token للمستخدم
$stmt = $con->prepare("
    SELECT fcm_token
    FROM user_device_tokens
    WHERE user_id = :uid
    ORDER BY updated_at DESC, id DESC
    LIMIT 1
");
$stmt->execute([':uid' => $user_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['fcm_token'])) {
    http_response_code(404);
    echo json_encode([
        'status'  => 'error',
        'message' => 'No token for this user'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetToken = $row['fcm_token'];

// 2) دالة للحصول على access_token من service account (JWT → OAuth2)
function getAccessToken($serviceAccountPath)
{
    if (!file_exists($serviceAccountPath)) {
        throw new Exception('Service account file not found at: ' . $serviceAccountPath);
    }

    $json = file_get_contents($serviceAccountPath);
    if ($json === false) {
        throw new Exception('Cannot read service account file');
    }

    $jsonKey = json_decode($json, true);
    if (!is_array($jsonKey) || empty($jsonKey['private_key']) || empty($jsonKey['client_email'])) {
        throw new Exception('Invalid service account JSON');
    }

    $now         = time();
    $expires     = $now + 3600; // صلاحية ساعة
    $privateKey  = $jsonKey['private_key'];
    $clientEmail = $jsonKey['client_email'];

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $payload = [
        'iss'   => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $expires,
    ];

    $base64UrlHeader  = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $base64UrlPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signatureInput   = $base64UrlHeader . '.' . $base64UrlPayload;

    if (!openssl_sign($signatureInput, $signature, $privateKey, 'sha256WithRSAEncryption')) {
        throw new Exception('openssl_sign failed – check OpenSSL extension and private key format');
    }

    $base64UrlSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    $jwt = $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;

    // طلب access_token من Google
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL error (token request): ' . $err);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($result, true);
    if ($httpCode !== 200 || !isset($data['access_token'])) {
        throw new Exception('Unable to get access_token. HTTP ' . $httpCode . ' Response: ' . $result);
    }

    return $data['access_token'];
}

// 2.1) الحصول على access_token
try {
    $accessToken = getAccessToken($serviceAccountPath);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3) بناء رسالة FCM HTTP v1
$message = [
    'message' => [
        'token' => $targetToken,
        'notification' => [
            'title' => $title,
            'body'  => $body,
        ],
        'data' => [
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ],
    ],
];

$url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

// 4) إرسال الطلب إلى FCM
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken,
    'Content-Type: application/json; charset=utf-8',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$result = curl_exec($ch);
if ($result === false) {
    $err = curl_error($ch);
    curl_close($ch);
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'cURL error (send): ' . $err
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode([
    'status'   => $httpCode === 200 ? 'ok' : 'error',
    'httpCode' => $httpCode,
    'raw'      => $result,                 // الرد الخام من FCM ليسهل الـ debug
    'response' => json_decode($result, true),
], JSON_UNESCAPED_UNICODE);
