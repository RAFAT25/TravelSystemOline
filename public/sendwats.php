<?php
require_once __DIR__ . "/../src/Services/Whapi.php";

try {
    $to   = $_POST["to"]   ?? null;
    $body = $_POST["body"] ?? null;

    if (!$to || !$body) {
        http_response_code(400);
        exit("Send POST: to, body");
    }

    $result = whapi_send_text($to, $body);

    header("content-type: application/json; charset=utf-8");
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage();
}
