<?php
namespace Travel\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use GuzzleHttp\Client;
use Exception;

class Whapi
{
    public static function sendText(string $to, string $message): array
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

        $json = (string) $res->getBody();
        return json_decode($json, true) ?: ["raw" => $json];
    }
}
