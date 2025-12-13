<?php

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function getFcmAccessTokenManual() {
    $serviceJson = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');
    if (!$serviceJson) {
        throw new Exception('FIREBASE_SERVICE_ACCOUNT_JSON is empty');
    }
    $service = json_decode($serviceJson, true);
    if (!$service) {
        throw new Exception('Invalid JSON in FIREBASE_SERVICE_ACCOUNT_JSON');
    }

    $privateKey  = openssl_pkey_get_private($service['private_key']);
    if (!$privateKey) {
        throw new Exception('Cannot load private key');
    }

    $clientEmail = $service['client_email'];
    $tokenUri    = $service['token_uri'];

    $now = time();
    $exp = $now + 3600; // صالح لساعة

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim  = [
        'iss'   => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => $tokenUri,
        'iat'   => $now,
        'exp'   => $exp,
    ];

    $base64Header = base64UrlEncode(json_encode($header));
    $base64Claim  = base64UrlEncode(json_encode($claim));
    $signatureInput = $base64Header . '.' . $base64Claim;

    $signature = '';
    if (!openssl_sign($signatureInput, $signature, $privateKey, 'sha256')) {
        throw new Exception('Failed to sign JWT');
    }
    $base64Signature = base64UrlEncode($signature);

    $jwt = $signatureInput . '.' . $base64Signature;

    // طلب access token
    $postFields = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $tokenUri,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => $postFields,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Curl error (token): ' . curl_error($ch));
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['access_token'])) {
        throw new Exception('No access_token in response: ' . $response);
    }
    return $json['access_token'];
}

function sendFcmV1ToTokenManual($fcmToken, $title, $body, $data = []) {
    $projectId = getenv('FIREBASE_PROJECT_ID');
    if (!$projectId) {
        throw new Exception('FIREBASE_PROJECT_ID is empty');
    }

    $accessToken = getFcmAccessTokenManual();

    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

    $message = [
        'message' => [
            'token' => $fcmToken,
            'notification' => [
                'title' => $title,
                'body'  => $body,
            ],
            'data' => $data,
        ],
    ];

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode($message),
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('Curl error (send): ' . curl_error($ch));
    }
    curl_close($ch);

    return json_decode($response, true);
}
