<?php
// src/Whapi.php
require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

function whapi_send_text(string $to, string $message): array
{
    $token = getenv("WHAPI_TOKEN");
    $base  = getenv("WHAPI_BASE") ?: "https://gate.whapi.cloud";

    if (!$token) {
        throw new Exception("WHAPI_TOKEN is missing");
    }

    $client = new Client([
        "base_uri" => $base,
        "timeout"  => 30,
    ]);

    $res = $client->post("/messages/text", [
        "headers" => [
            "accept"        => "application/json",
            "authorization" => "Bearer " . $token,
            "content-type"  => "application/json",
        ],
        "json" => [
            "to"   => $to,
            "body" => $message,
        ],
    ]);

    // Whapi يرجع JSON؛ نحوله إلى array
    $json = (string) $res->getBody();
    return json_decode($json, true) ?: ["raw" => $json];
}
