<?php
declare(strict_types=1);

/**
 * includes/admin_verification.php — admin registration code helpers.
 * Reuses the PHPMailer setup + constants from verification.php.
 */

require_once __DIR__ . '/verification.php';
require_once __DIR__ . '/env.php';

/** Invite code required to create an admin account (set ADMIN_INVITE_CODE in .env). */
function admin_invite_code(): string
{
    load_env();   // mail_config.php only reads .env when an email is sent, which is too late here
    $v = getenv('ADMIN_INVITE_CODE');
    if ($v === false || $v === '') {
        $v = $_ENV['ADMIN_INVITE_CODE'] ?? $_SERVER['ADMIN_INVITE_CODE'] ?? '';
    }
    return trim((string)$v);
}

/** Create a fresh code for an admin, store its hash, and email it. True if the email was sent. */
function issue_admin_verification_code(PDO $pdo, int $adminId, string $email, string $name): bool
{
    $code      = generate_verification_code();
    $expiresAt = (new DateTimeImmutable('+' . VERIFY_CODE_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

    $pdo->prepare(
        'INSERT INTO admin_email_verifications (admin_id, email, code_hash, expires_at)
         VALUES (?, ?, ?, ?)'
    )->execute([$adminId, $email, password_hash($code, PASSWORD_DEFAULT), $expiresAt]);

    $first = explode(' ', trim($name))[0] ?: 'there';

    return send_code_email(
        $email,
        $name,
        $code,
        'Your R&R Sweet Bites admin verification code',
        "Welcome, {$first}!",
        'Here is your 6-digit code to verify your admin account:',
        "If you didn't create an admin account, ignore this email."
    );
}