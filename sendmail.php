<?php
$brevoEmail  = getenv('BREVO_EMAIL');
$brevoApiKey = getenv('BREVO_API_KEY');

$data = [
    'sender' => [
        'email' => $brevoEmail,
        'name'  => 'منصه احجزلي'
    ],
    'to' => [
        [
            'email' => 'rafatkang@gmail.com',
            'name'  => 'Rafat'
        ]
    ],
    'subject' => 'اختبار Brevo API من Render',
    'htmlContent' => '<b>تم الإرسال عبر Brevo REST API من Render مع Environment Variables</b>'
];

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.brevo.com/v3/smtp/email',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'accept: application/json',
        'api-key: ' . $brevoApiKey,
        'content-type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($data),
]);

$response = curl_exec($ch);
$error    = curl_error($ch);

curl_close($ch);

if ($error) {
    echo 'Curl error: ' . $error;
} else {
    echo 'Brevo API response: ' . $response;
}
