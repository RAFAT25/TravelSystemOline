<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'config.php';

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.zoho.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = ZOHO_EMAIL;   // نفس الإيميل
    $mail->Password   = ZOHO_PASS;   // كلمة المرور أو App Password
    $mail->SMTPSecure = 'ssl';       // لــ 465 يجب SSL
    $mail->Port       = 465;

    $mail->setFrom(ZOHO_EMAIL, 'منصه احجزلي');
    $mail->addAddress('rafatkang@gmail.com', 'Rafat');

    $mail->isHTML(true);
    $mail->Subject = 'اختبار Zoho SMTP';
    $mail->Body    = '<b>رسالة تجريبية من Zoho SMTP</b>';

    $mail->send();
    echo 'تم إرسال الإيميل بنجاح';
} catch (Exception $e) {
    echo "خطأ: {$mail->ErrorInfo}";
}
