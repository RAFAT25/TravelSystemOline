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

        return '
        <!doctype html>
        <html lang="ar" dir="rtl">
        <head>
          <meta charset="UTF-8">
          <title>تأكيد البريد الإلكتروني - منصّة احجزلي</title>
          <style>
            body { background:#f4f4f7; font-family:Tahoma, Arial,sans-serif; margin:0; padding:0; }
            .wrapper { width:100%; padding:24px 0; }
            .container { max-width:600px; margin:0 auto; background:#ffffff; border-radius:10px; overflow:hidden;
                         box-shadow:0 2px 10px rgba(15,23,42,0.08); }
            .header { background:#1d4ed8; color:#ffffff; padding:18px 24px; font-size:20px; font-weight:bold; text-align:right; }
            .body { padding:24px; color:#0f172a; font-size:15px; line-height:1.8; text-align:right; }
            .body p { margin:0 0 12px; }
            .code-box { margin:18px 0; padding:14px 18px; background:#eff6ff; border-radius:8px; font-size:22px;
                        letter-spacing:4px; text-align:center; color:#1d4ed8; font-weight:bold; }
            .meta { margin-top:16px; font-size:12px; color:#64748b; }
            .footer { padding:14px 24px 18px; font-size:12px; color:#94a3b8; text-align:center; }
          </style>
        </head>
        <body>
          <div class="wrapper">
            <div class="container">
              <div class="header">منصّة احجزلي</div>
              <div class="body">
                <p>مرحباً <strong>' . $safeName . '</strong>,</p>
                <p>شكرًا لتسجيلك في <strong>منصّة احجزلي</strong>. لإكمال عملية إنشاء الحساب وتفعيل بريدك الإلكتروني، يرجى استخدام كود التحقق التالي:</p>
                <div class="code-box">' . $verificationCode . '</div>
                <p class="meta">
                  كود التفعيل صالح لفترة محدودة. إذا لم تقم أنت بإنشاء هذا الحساب، يمكنك تجاهل هذا البريد بأمان.
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
