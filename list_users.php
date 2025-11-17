<?php
header("Content-Type: application/json; charset=UTF-8");

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$password = getenv('DB_PASSWORD');
$sslmode = getenv('DB_SSLMODE');

$conn = pg_connect("host=$host port=$port dbname=$dbname user=$user password=$password sslmode=$sslmode");

if(!$conn){
    echo json_encode(["error" => "فشل الاتصال بقاعدة البيانات!"]);
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
