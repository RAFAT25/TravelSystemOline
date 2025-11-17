<?php
header("Content-Type: application/json; charset=UTF-8");

// بيانات الاتصال
$host = 'ep-weathered-sea-ahagsdqv-pooler.c-3.us-east-1.aws.neon.tech';
$port = '5432';
$dbname = 'neondb';
$user = 'neondb_owner';
$password = 'npg_6Lh8BTSKHfxg';
$sslmode = 'require';
$endpoint = 'ep-weathered-sea-ahagsdqv';

$conn_string = "host=$host port=$port dbname=$dbname user=$user password=$password sslmode=$sslmode options='endpoint=$endpoint'";
$conn = pg_connect($conn_string);

if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'فشل الاتصال بقاعدة البيانات!']);
    exit;
}

// استعلام البيانات من جدول users
$query = "SELECT * FROM users";
$result = pg_query($conn, $query);

if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => pg_last_error($conn)]);
    exit;
}

$users = [];
while ($row = pg_fetch_assoc($result)) {
    $users[] = $row;
}

echo json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

pg_close($conn);
?>
