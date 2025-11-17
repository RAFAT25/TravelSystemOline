<?php
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$password = getenv('DB_PASSWORD');
$sslmode = getenv('DB_SSLMODE'); // يمكن وضعها مباشرة "require"

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode";

try {
    $con = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die(json_encode([
        "success" => false,
        "error" => "خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE));
}
?>
