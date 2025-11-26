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
    $mail->Host       = 'smtp-relay.brevo.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'rafat.mohammed.dev@gmail.com';
    $mail->Password   = 'xsmtpsib-4b1cc8d601da34680c8f25c1d89c1be3429b15961144c82306b7b583314a746c-D50RChIMCsDvvuUX';
    $mail->SMTPSecure = false;
    $mail->SMTPAutoTLS= false;
    $mail->Port= 587;

    $mail->setFrom(BREVO_EMAIL, 'منصه احجزلي');
    $mail->addAddress('rafatkang@gmail.com', 'Rafat');

    $mail->isHTML(true);
    $mail->Subject = 'Brevo Local Test';
    $mail->Body    = '<b>Test email sent via Brevo + PHPMailer</b>';
    $mail->SMTPDebug=2;
       
    $mail->send();
    echo 'Email sent successfully';
} catch (Exception $e) {
    echo "Error: {$mail->ErrorInfo}";
}
?>
