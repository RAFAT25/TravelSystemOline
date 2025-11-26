<?php
require 'config.php'; // هذا الملف يحتوي على التعاريف

$emailData = [
    'sender' => ['email' => BREVO_EMAIL, 'name' => 'منصه احجزلي'],
    'to' => [['email' => 'rafatkang@gmail.com', 'name' => 'Rafat']],
    'subject' => 'اختبار API Brevo',
    'htmlContent' => '<b>نجح الإرسال عبر Brevo REST API! 🚀</b>'
];

$curl = curl_init();

curl_setopt_array($curl, [
    CURLOPT_URL => "https://api.brevo.com/v3/smtp/email",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($emailData),
    CURLOPT_HTTPHEADER => [
        "accept: application/json",
        "api-key: " . BREVO_API_KEY,
        "content-type: application/json"
    ]
]);

$response = curl_exec($curl);

if (curl_errno($curl)) {
    echo 'Curl error: ' . curl_error($curl);
} else {
    echo 'API Response: ' . $response;
}

curl_close($curl);
?>
