<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Travel\Services\Whapi;

header("content-type: application/json; charset=utf-8");

try {
    // قراءة JSON من جسم الطلب
    $raw    = file_get_contents('php://input');
    $data   = json_decode($raw, true); // true = مصفوفة
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $to   = $data["to"]   ?? null;
    $body = $data["body"] ?? null;

    if (!$to || !$body) {
        http_response_code(400);
        echo json_encode(["error" => "Send 'to' and 'body' in JSON"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = Whapi::sendText($to, $body);

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
