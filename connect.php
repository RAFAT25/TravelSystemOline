<?php
$host = 'ep-weathered-sea-ahagsdqv-pooler.c-3.us-east-1.aws.neon.tech';
$port = '5432';
$dbname = 'neondb';
$user = 'neondb_owner';
$password = 'npg_6Lh8BTSKHfxg';
$sslmode = 'require';
$endpoint = 'ep-weathered-sea-ahagsdqv';

$dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode;options='endpoint=$endpoint'";

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
