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
                "to"   => self::formatPhoneNumber($to),
                "body" => $message,
            ],
        ]);

        $json = (string) $res->getBody();
        return json_decode($json, true) ?: ["raw" => $json];
    }
    }

    private static function formatPhoneNumber(string $phone): string
    {
        // 1. Remove any non-numeric characters
        $clean = preg_replace('/[^0-9]/', '', $phone);

        // 2. Check if it handles Yemen local format (e.g., 77xxxxxxx)
        // If it starts with 7 and is 9 digits, it's likely a Yemen mobile number without country code
        if (strlen($clean) === 9 && strpos($clean, '7') === 0) {
            return '967' . $clean;
        }

        // 3. If it's already international (e.g. 96777xxxxxxx), keep it
        return $clean;
    }
}
