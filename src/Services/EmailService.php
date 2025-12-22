<?php
namespace Travel\Services;

use GuzzleHttp\Client;
use Exception;

class EmailService {
    private static function getClient() {
        return new Client([
            'base_uri' => 'https://api.brevo.com/v3/',
            'timeout'  => 30,
        ]);
    }

    public static function sendVerificationCode($toEmail, $code, $name = 'User') {
        $apiKey = getenv('BREVO_API_KEY');
        $senderEmail = getenv('BREVO_EMAIL') ?: 'no-reply@travel.com';

        if (!$apiKey) {
            // If No API key, we fail gracefully or use log
            return ["success" => false, "error" => "BREVO_API_KEY missing"];
        }

        $client = self::getClient();

        try {
            $response = $client->post('smtp/email', [
                'headers' => [
                    'api-key' => $apiKey,
                    'accept'  => 'application/json',
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'sender' => ['name' => 'Travel System', 'email' => $senderEmail],
                    'to' => [['email' => $toEmail, 'name' => $name]],
                    'subject' => 'Verification Code',
                    'htmlContent' => "<html><body><h3>Hello $name,</h3><p>Your verification code is: <b>$code</b></p><p>Thank you for using our service.</p></body></html>",
                ]
            ]);

            return [
                "success" => true,
                "data" => json_decode($response->getBody(), true)
            ];
        } catch (Exception $e) {
            return [
                "success" => false,
                "error" => $e->getMessage()
            ];
        }
    }
}
