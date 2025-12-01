<?php
header('Content-Type: application/json; charset=utf-8');

require 'connect.php'; // يحتوي على $con

// إعدادات Firebase
$serviceAccountPath = __DIR__ . 'firebase-service-account.json'; // عدّل المسار لو مختلف
$projectId = 'unified-adviser-408114';
// ضع هنا Project ID من Firebase

$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$title   = $_POST['title'] ?? 'Test title';
$body    = $_POST['body']  ?? 'Test body';

if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing user_id'], JSON_UNESCAPED_UNICODE);
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

if (!$row) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No token for this user'], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetToken = $row['fcm_token'];

// 2) دالة للحصول على access_token من service account (JWT → OAuth2)
function getAccessToken($serviceAccountPath)
{
    $jsonKey = json_decode(file_get_contents($serviceAccountPath), true);

    $now        = time();
    $expires    = $now + 3600;
    $privateKey = $jsonKey['private_key'];
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

    openssl_sign($signatureInput, $signature, $privateKey, 'sha256WithRSAEncryption');
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
        throw new Exception('cURL error: ' . curl_error($ch));
    }
    curl_close($ch);

    $data = json_decode($result, true);
    if (!isset($data['access_token'])) {
        throw new Exception('Unable to get access_token: ' . $result);
    }

    return $data['access_token'];
}

try {
    $accessToken = getAccessToken($serviceAccountPath);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'cURL error: ' . curl_error($ch)], JSON_UNESCAPED_UNICODE);
    curl_close($ch);
    exit;
}
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo json_encode([
    'status'   => $httpCode === 200 ? 'ok' : 'error',
    'response' => json_decode($result, true),
], JSON_UNESCAPED_UNICODE);
