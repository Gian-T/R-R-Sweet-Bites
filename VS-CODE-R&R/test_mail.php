<?php
require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

$cfg  = require __DIR__ . '/includes/mail_config.php';
$mail = new PHPMailer(true);

try {
    $mail->SMTPDebug   = 2;
    $mail->Debugoutput = 'html';
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $cfg['port'];
    $mail->setFrom($cfg['from'], $cfg['from_name']);
    $mail->addAddress($cfg['username']);   // send to yourself
    $mail->Subject = 'Test';
    $mail->Body    = 'It works.';
    $mail->send();
    echo '<h3>Sent!</h3>';
} catch (Throwable $e) {
    echo '<h3>Failed: ' . htmlspecialchars($e->getMessage()) . '</h3>';
}