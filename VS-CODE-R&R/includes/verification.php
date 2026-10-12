<?php
declare(strict_types=1);

/**
 * includes/verification.php — shared email-verification helpers.
 * Used by register.php, verify-email.php, resend-code.php and login_code.php.
 */

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

const VERIFY_CODE_TTL_MINUTES        = 15;  // how long a code stays valid
const VERIFY_MAX_ATTEMPTS            = 5;   // wrong guesses allowed per code
const VERIFY_RESEND_COOLDOWN_SECONDS = 60;  // wait between resends
const VERIFY_RESEND_MAX_PER_HOUR     = 5;   // max codes per account per hour

function generate_verification_code(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** True if the email already belongs to an admin OR a customer. */
function email_in_use(PDO $pdo, string $email): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM admin    WHERE email = ?
         UNION
         SELECT 1 FROM customer WHERE email = ?
         LIMIT 1'
    );
    $st->execute([$email, $email]);
    return (bool)$st->fetchColumn();
}

/** Build a configured PHPMailer, or null if credentials are missing. */
function make_mailer(): ?PHPMailer
{
    $cfg = require __DIR__ . '/mail_config.php';

    if ($cfg['username'] === '' || $cfg['password'] === '') {
        error_log('[PHPMailer] MAIL_USERNAME or MAIL_PASSWORD is empty. Check .env.');
        return null;
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = $cfg['secure'] === 'tls'
        ? PHPMailer::ENCRYPTION_STARTTLS
        : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = $cfg['port'];
    $mail->Timeout    = 15;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom($cfg['from'], $cfg['from_name']);
    if ($cfg['reply_to'] !== '') {
        $mail->addReplyTo($cfg['reply_to'], $cfg['from_name']);
    }
    $mail->isHTML(true);
    return $mail;
}

/** Generic code email. Returns true on success; failures are logged, never thrown. */
function send_code_email(
    string $toEmail,
    string $toName,
    string $code,
    string $subject,
    string $heading,
    string $intro,
    string $footer
): bool {
    $mail = null;

    try {
        $mail = make_mailer();
        if ($mail === null) return false;

        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;

        $h   = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $ttl = VERIFY_CODE_TTL_MINUTES;

        $mail->Body = "
            <div style=\"font-family:Arial,Helvetica,sans-serif;color:#4a2a2e;max-width:520px;margin:auto\">
              <h2 style=\"color:#d9788a;margin:0 0 12px\">{$h($heading)}</h2>
              <p>{$h($intro)}</p>
              <p style=\"font-size:32px;font-weight:bold;letter-spacing:8px;
                        background:#fbe6e6;color:#b3263e;text-align:center;
                        padding:14px;border-radius:10px;margin:18px 0\">{$h($code)}</p>
              <p>This code expires in <strong>{$ttl} minutes</strong>.</p>
              <p style=\"color:#8a6a62;font-size:12px\">{$h($footer)}</p>
              <hr style=\"border:none;border-top:1px solid #eee;margin:20px 0\">
              <p style=\"color:#8a6a62;font-size:12px\">R&amp;R Sweet Bites — Custom cakes made just for you.</p>
            </div>
        ";
        $mail->AltBody = "{$heading}\n\n{$intro}\n\nCode: {$code}\n\n"
                       . "This code expires in {$ttl} minutes.\n\n{$footer}\n";

        $mail->send();
        return true;

    } catch (Throwable $e) {
        error_log("[PHPMailer] to={$toEmail} error=" . ($mail?->ErrorInfo ?? '') . ' ' . $e->getMessage());
        return false;
    }
}

/** Registration email. Returns true on success; failures are logged, never thrown. */
function send_verification_email(string $toEmail, string $toName, string $code): bool
{
    return send_code_email(
        $toEmail,
        $toName,
        $code,
        'Your R&R Sweet Bites verification code',
        "Welcome, {$toName}!",
        'Here is your 6-digit verification code:',
        "If you didn't request this, ignore this email."
    );
}

/**
 * Create a fresh code for a customer, store its hash, and email it.
 * Older codes stay in the table (for rate limiting) but only the newest is ever checked.
 * Returns true if the email was sent.
 */
function issue_verification_code(PDO $pdo, int $customerId, string $email, string $name): bool
{
    $code      = generate_verification_code();
    $codeHash  = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = (new DateTimeImmutable('+' . VERIFY_CODE_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

    $pdo->prepare(
        'INSERT INTO email_verifications (customer_id, email, code_hash, expires_at)
         VALUES (?, ?, ?, ?)'
    )->execute([$customerId, $email, $codeHash, $expiresAt]);

    return send_verification_email($email, $name, $code);
}