<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Travel\Services\Whapi;

header("content-type: application/json; charset=utf-8");

try {
    $to   = $_POST["to"]   ?? $_GET["to"]   ?? null;
    $body = $_POST["body"] ?? $_GET["body"] ?? null;

    if (!$to || !$body) {
        http_response_code(400);
        echo json_encode(["error" => "Send 'to' and 'body'"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = Whapi::sendText($to, $body);

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
