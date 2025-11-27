<?php
// services/BrevoMailer.php

class BrevoMailer
{
    private string $apiKey;
    private string $fromEmail;
    private string $fromName;

    public function __construct()
    {
        $this->apiKey    = getenv('BREVO_API_KEY');
        $this->fromEmail = getenv('BREVO_EMAIL');
        $this->fromName  = 'منصّة احجزلي';
    }

    public function sendVerificationEmail(string $toEmail, string $toName, int $verificationCode): array
    {
        $html = $this->buildVerificationTemplate($toName, $verificationCode);

        $payload = [
            'sender' => [
                'email' => $this->fromEmail,
                'name'  => $this->fromName
            ],
            'to' => [
                [
                    'email' => $toEmail,
                    'name'  => $toName
                ]
            ],
            'subject' => 'كود تفعيل حسابك في منصّة احجزلي',
            'htmlContent' => $html
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.brevo.com/v3/smtp/email',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $this->apiKey,
                'content-type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        return [
            'success'  => $error ? false : true,
            'error'    => $error,
            'response' => $response
        ];
    }

    private function buildVerificationTemplate(string $fullName, int $verificationCode): string
    {
        $safeName = htmlspecialchars($fullName, ENT_QUOTES, "UTF-8");
        $logoUrl  = 'https://travelsystemoline.onrender.com/logo.PNG'; // غيّرها لرابط لوجو منصّتك

        return '
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <title>تأكيد البريد الإلكتروني - منصّة احجزلي</title>
  <style>
    body {
      margin:0;
      padding:0;
      background:#020617;
      font-family:Tahoma, Arial, sans-serif;
    }
    .wrapper {
      width:100%;
      padding:32px 12px;
      background:radial-gradient(circle at top, #1d4ed8 0, #020617 45%);
    }
    .container {
      max-width:620px;
      margin:0 auto;
      background:#020617;
      border-radius:18px;
      overflow:hidden;
      border:1px solid rgba(148,163,184,0.25);
      box-shadow:0 18px 45px rgba(15,23,42,0.85);
    }
    .header {
      padding:22px 28px 12px;
      text-align:center;
      background:linear-gradient(135deg, rgba(15,23,42,0.95), rgba(15,23,42,0.98));
      border-bottom:1px solid rgba(148,163,184,0.25);
    }
    .logo {
      width:72px;
      height:72px;
      border-radius:999px;
      border:2px solid #38bdf8;
      overflow:hidden;
      margin:0 auto 10px;
      box-shadow:0 0 0 4px rgba(56,189,248,0.15);
      background:#020617;
    }
    .logo img {
      width:100%;
      height:100%;
      display:block;
    }
    .brand {
      font-size:18px;
      font-weight:bold;
      color:#e5e7eb;
      letter-spacing:0.5px;
    }
    .tagline {
      font-size:12px;
      color:#9ca3af;
      margin-top:4px;
    }
    .body {
      padding:22px 26px 24px;
      color:#e5e7eb;
      font-size:14px;
      line-height:1.9;
      background:radial-gradient(circle at top left, rgba(56,189,248,0.08), transparent 55%),
                 radial-gradient(circle at bottom right, rgba(59,130,246,0.08), transparent 55%);
    }
    .body p {
      margin:0 0 10px;
    }
    .hi {
      font-size:15px;
    }
    .highlight {
      color:#38bdf8;
      font-weight:bold;
    }
    .code-box {
      margin:18px 0 8px;
      padding:14px 18px;
      background:linear-gradient(135deg, #020617, #020617);
      border-radius:12px;
      border:1px solid rgba(148,163,184,0.6);
      font-size:22px;
      letter-spacing:4px;
      text-align:center;
      color:#38bdf8;
      font-weight:bold;
      box-shadow:0 10px 25px rgba(15,23,42,0.9);
    }
    .hint {
      font-size:11px;
      color:#9ca3af;
      margin-top:4px;
    }
    .meta {
      margin-top:16px;
      font-size:11px;
      color:#9ca3af;
    }
    .footer {
      padding:12px 26px 16px;
      font-size:11px;
      color:#6b7280;
      text-align:center;
      background:#020617;
      border-top:1px solid rgba(148,163,184,0.25);
    }
    @media only screen and (max-width: 480px) {
      .wrapper { padding:20px 8px; }
      .container { border-radius:14px; }
      .body { padding:18px 16px 20px; }
      .code-box { font-size:20px; letter-spacing:3px; }
    }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="container">
      <div class="header">
        <div class="logo">
          <img src="' . $logoUrl . '" alt="منصّة احجزلي">
        </div>
        <div class="brand">منصّة احجزلي</div>
        <div class="tagline">إدارة وحجز رحلاتك بكل سهولة</div>
      </div>
      <div class="body">
        <p class="hi">مرحباً <span class="highlight">' . $safeName . '</span>,</p>
        <p>شكرًا لانضمامك إلى <strong>منصّة احجزلي</strong>. قبل أن نبدأ، نحتاج لتأكيد أن هذا البريد يعود لك.</p>
        <p>يرجى إدخال كود التحقق التالي داخل التطبيق لتفعيل حسابك:</p>
        <div class="code-box">' . $verificationCode . '</div>
        <p class="hint">لأمان حسابك، لا تشارك هذا الكود مع أي شخص آخر.</p>
        <p class="meta">
          إذا لم تقم أنت بإنشاء هذا الحساب، يمكنك تجاهل هذا البريد بأمان، ولن يتم تفعيل الحساب بدون إدخال الكود.
        </p>
      </div>
      <div class="footer">
        © ' . date('Y') . ' منصّة احجزلي – جميع الحقوق محفوظة.
      </div>
    </div>
  </div>
</body>
</html>';
    }
}
