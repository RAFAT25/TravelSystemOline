<?php
require_once 'connect.php';  // كودك الأصلي

define('FCM_SERVER_KEY', getenv('FCM_SERVER_KEY'));
define('FCM_URL', 'https://fcm.googleapis.com/fcm/send');

echo "🚀 بدء الاستماع...\n";

// للـ LISTEN نحتاج pg_connect منفصل عن PDO
$pg_dsn = "host=" . getenv('DB_HOST') . 
          " port=" . getenv('DB_PORT') . 
          " dbname=" . getenv('DB_NAME') . 
          " user=" . getenv('DB_USER') . 
          " password=" . getenv('DB_PASSWORD') . 
          " sslmode=" . getenv('DB_SSLMODE');

$pg_conn = pg_connect($pg_dsn);
if (!$pg_conn) die('❌ خطأ pg_connect: ' . pg_last_error());

pg_exec($pg_conn, "LISTEN fcm_changes");
echo "✅ متصل ويستمع لـ fcm_changes\n";

function sendFCM($title, $body, $token) {
    $data = ['to' => $token, 'notification' => ['title' => $title, 'body' => $body]];
    $headers = ['Authorization: key=' . FCM_SERVER_KEY, 'Content-Type: application/json'];
    
    $ch = curl_init(FCM_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode($data)
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function getUserToken($pdo_conn, $userId) {
    global $con;  // استخدام $con من db_connection.php
    $stmt = $con->prepare("SELECT fcm_token FROM user_tokens WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch()['fcm_token'] ?? null;
}

while (true) {
    $notification = pg_get_notify($pg_conn, 1);
    
    if ($notification) {
        $payload = json_decode($notification['payload'], true);
        echo "📨 تلقي: " . json_encode($payload) . "\n";
        
        if ($payload['table'] === 'bookings' && $payload['operation'] === 'INSERT') {
            $userId = $payload['new_data']['user_id'];
            $token = getUserToken($con, $userId);
            
            if ($token) {
                $bookingId = $payload['new_data']['id'] ?? 'غير معروف';
                $fcmResult = sendFCM('حجز جديد!', "تم تأكيد حجزك #$bookingId", $token);
                echo "✅ FCM مرسل: $fcmResult\n";
            } else {
                echo "⚠️ لا token للمستخدم $userId\n";
            }
        }
    }
}
?>
