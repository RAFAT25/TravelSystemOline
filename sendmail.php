<?php
$brevoEmail  = getenv('BREVO_EMAIL');
$brevoApiKey = getenv('BREVO_API_KEY');

// قالب HTML احترافي (RTL + ألوان مناسبة)
$html = '
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <title>مرحباً بك في منصّة احجزلي</title>
  <style>
    body { background:#f4f4f7; font-family:Tahoma, Arial,sans-serif; margin:0; padding:0; }
    .wrapper { width:100%; padding:24px 0; }
    .container { max-width:600px; margin:0 auto; background:#ffffff; border-radius:10px; overflow:hidden;
                 box-shadow:0 2px 10px rgba(15,23,42,0.08); }
    .header { background:#1d4ed8; color:#ffffff; padding:18px 24px; font-size:20px; font-weight:bold; text-align:right; }
    .body { padding:24px; color:#0f172a; font-size:15px; line-height:1.8; text-align:right; }
    .body p { margin:0 0 12px; }
    .btn { display:inline-block; margin-top:16px; background:#1d4ed8; color:#ffffff !important;
           padding:10px 22px; border-radius:999px; text-decoration:none; font-size:14px; }
    .btn:hover { background:#1e40af; }
    .meta { margin-top:20px; font-size:12px; color:#64748b; }
    .footer { padding:14px 24px 18px; font-size:12px; color:#94a3b8; text-align:center; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="container">
      <div class="header">منصّة احجزلي</div>
      <div class="body">
        <p>مرحباً <strong>Rafat</strong>,</p>
        <p>تم إنشاء حسابك بنجاح في <strong>منصّة احجزلي</strong>. يمكنك الآن تصفّح الرحلات وإدارة حجوزاتك بكل سهولة.</p>
        <a href="https://your-domain.com" class="btn">الدخول إلى المنصّة</a>
        <p class="meta">
          إذا لم تقم أنت بهذا الإجراء، يرجى تجاهل هذا البريد أو التواصل مع فريق الدعم الخاص بنا.
        </p>
      </div>
      <div class="footer">
        © ' . date('Y') . ' منصّة احجزلي – جميع الحقوق محفوظة.
      </div>
    </div>
  </div>
</body>
</html>';

$data = [
    'sender' => [
        'email' => $brevoEmail,
        'name'  => 'منصّة احجزلي'
    ],
    'to' => [
        [
            'email' => 'rafat.mohammed.dev@gmail.com',
            'name'  => 'Rafat'
        ]
    ],
    'subject' => 'مرحباً بك في منصّة احجزلي',
    'htmlContent' => $html
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
