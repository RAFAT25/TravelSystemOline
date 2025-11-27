<?php
// config/database.php

function getConnection(): PDO
{
    $host     = getenv('DB_HOST');
    $port     = getenv('DB_PORT') ?: '5432';
    $dbname   = getenv('DB_NAME');
    $user     = getenv('DB_USER');
    $password = getenv('DB_PASSWORD');
    $sslmode  = getenv('DB_SSLMODE') ?: 'require'; // حسب إعدادات Neon/Render

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode};options='--client_encoding=UTF8'";

    try {
        $con = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $con;
    } catch (PDOException $e) {
        // في الـ API الحقيقي يفضل تسجيل الخطأ في log وليس طباعته للمستخدم
        http_response_code(500);
        die(json_encode([
            "success" => false,
            "error"   => "خطأ في الاتصال بقاعدة البيانات"
        ], JSON_UNESCAPED_UNICODE));
    }
}
