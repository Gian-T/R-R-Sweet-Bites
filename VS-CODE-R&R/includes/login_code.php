<?php
declare(strict_types=1);

/**
 * includes/login_code.php — helpers for "password, then emailed code" login.
 *
 * Flow:
 *   login.php         password OK  → $_SESSION['pending_login'] + code emailed (NOT signed in yet)
 *   login-verify.php  code OK      → real session is created
 *   login-resend.php               → new code for the pending login
 */

require_once __DIR__ . '/verification.php';

const LOGIN_REMEMBER_DAYS       = 30;
const LOGIN_PENDING_TTL_SECONDS = VERIFY_CODE_TTL_MINUTES * 60;

if (!function_exists('redirect_path_for_role')) {
    function redirect_path_for_role(string $role): string
    {
        return $role === 'admin'
            ? '../private/order-requests.html'
            : '../private/order-requests.html';
    }
}

/** j***@gmail.com */
function mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    $local = $parts[0];
    $domain = $parts[1] ?? '';
    return substr($local, 0, 1) . str_repeat('*', max(2, strlen($local) - 1)) . '@' . $domain;
}

/** Newest-code age (seconds, DB clock) and how many codes were sent in the last hour. */
function login_code_status(PDO $pdo, string $role, int $uid): array
{
    $st = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS age,
                SUM(created_at > (NOW() - INTERVAL 1 HOUR))   AS recent
         FROM login_codes WHERE role = ? AND user_id = ?'
    );
    $st->execute([$role, $uid]);
    $r = $st->fetch();
    return [
        'age'    => ($r['age'] ?? null) !== null ? (int)$r['age'] : null,
        'recent' => (int)($r['recent'] ?? 0),
    ];
}

/** Store a new login code (hashed) and email it. Returns true if the email was sent. */
function issue_login_code(PDO $pdo, string $role, int $uid, string $email, string $name): bool
{
    $code      = generate_verification_code();
    $expiresAt = (new DateTimeImmutable('+' . VERIFY_CODE_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

    $pdo->prepare(
        'INSERT INTO login_codes (role, user_id, code_hash, expires_at) VALUES (?, ?, ?, ?)'
    )->execute([$role, $uid, password_hash($code, PASSWORD_DEFAULT), $expiresAt]);

    $first = explode(' ', trim($name))[0] ?: 'there';

    return send_code_email(
        $email,
        $name,
        $code,
        'Your R&R Sweet Bites login code',
        "Hi, {$first}!",
        'Use this code to finish logging in:',
        "If you didn't try to log in, someone may know your password. Please change it."
    );
}

/** The half-finished login for this browser session, or null if none/expired. */
function login_pending(): ?array
{
    $p = $_SESSION['pending_login'] ?? null;
    if (!is_array($p) || (int)($p['expires'] ?? 0) < time()) {
        unset($_SESSION['pending_login']);
        return null;
    }
    return $p;
}

/** Turn a pending login into a real signed-in session. */
function complete_login(array $p): void
{
    session_regenerate_id(true);   // stop session fixation
    unset($_SESSION['pending_login']);

    $_SESSION['uid']  = (int)$p['id'];
    $_SESSION['role'] = $p['role'];
    if ($p['role'] === 'admin') {
        $_SESSION['admin_id'] = (int)$p['id'];
    } else {
        $_SESSION['customer_id'] = (int)$p['id'];
    }

    if (!empty($p['remember'])) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + LOGIN_REMEMBER_DAYS * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
    }
}