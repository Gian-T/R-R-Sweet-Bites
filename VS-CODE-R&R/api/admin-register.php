<?php
declare(strict_types=1);

/**
 * api/admin-register.php — Admin registration (emails a 6-digit code).
 *
 * Accepts: POST JSON { fullName, email, password, confirm, inviteCode, website }
 * Returns: 201 { ok: true, message, email_sent }
 *          422 { ok: false, message, errors }
 *          403 { ok: false, message, errors? }          closed / wrong invite code
 *          409 { ok: false, message, errors|unverified } duplicate email
 *          429 { ok: false, message }                   too many invite-code guesses
 *
 * Who may register: the very first admin (empty admin table) with no invite code
 * configured, otherwise ADMIN_INVITE_CODE (.env) must be supplied and match.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/verification.php';
require_once __DIR__ . '/../includes/admin_verification.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

const ADMIN_REG_BUCKET       = 'admin-register';   // stored in login_attempts.email
const ADMIN_REG_MAX_GUESSES  = 5;

try {
    $in = json_input();

    // Bot trap: pretend it worked.
    if (!empty($in['website'])) {
        json_out(['ok' => true, 'message' => 'Account created! Check your email for a verification code.']);
    }

    $name     = trim((string)preg_replace('/\s+/u', ' ', (string)($in['fullName'] ?? '')));
    $email    = strtolower(trim((string)($in['email'] ?? '')));
    $password = (string)($in['password'] ?? '');
    $confirm  = (string)($in['confirm'] ?? '');
    $invite   = trim((string)($in['inviteCode'] ?? ''));

    $errors = array_filter([
        'fullName' => v_name($name),
        'email'    => v_email($email),
        'password' => v_password($password),
        'confirm'  => $password !== $confirm ? 'Passwords do not match.' : null,
    ]);
    if ($errors) {
        json_out(['ok' => false, 'message' => 'Please fix the highlighted fields.', 'errors' => $errors], 422);
    }

    $pdo = db();
    $ip  = client_ip();

    /* ---- Gate: invite code (or first-admin bootstrap) ---- */
    $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn();
    $required   = admin_invite_code();
    $needInvite = $adminCount > 0 || $required !== '';

    if ($needInvite) {
        if ($required === '') {
            json_out(['ok' => false, 'message' => 'Admin registration is closed. Ask the shop owner for access.'], 403);
        }

        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email = ? AND ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
        );
        $st->execute([ADMIN_REG_BUCKET, $ip]);
        if ((int)$st->fetchColumn() >= ADMIN_REG_MAX_GUESSES) {
            json_out(['ok' => false, 'message' => 'Too many attempts. Please try again in 15 minutes.'], 429);
        }

        if ($invite === '' || !hash_equals($required, $invite)) {
            $pdo->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([ADMIN_REG_BUCKET, $ip]);
            json_out([
                'ok'      => false,
                'message' => 'Invalid admin invite code.',
                'errors'  => ['inviteCode' => 'Invalid admin invite code.'],
            ], 403);
        }
    }

    /* ---- Duplicate email? ---- */
    $st = $pdo->prepare('SELECT admin_id, email_verified_at FROM admin WHERE email = ?');
    $st->execute([$email]);
    $existing = $st->fetch();
    if ($existing && $existing['email_verified_at'] === null) {
        json_out([
            'ok'         => false,
            'unverified' => true,
            'message'    => 'This email is registered but not verified yet.',
        ], 409);
    }
    if ($existing || email_in_use($pdo, $email)) {
        json_out([
            'ok'      => false,
            'message' => 'Email is already registered. Log in instead.',
            'errors'  => ['email' => 'Email is already registered. Log in instead.'],
        ], 409);
    }

    $pdo->prepare('INSERT INTO admin (full_name, email, password_hash) VALUES (?, ?, ?)')
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $adminId = (int)$pdo->lastInsertId();

    $sent = issue_admin_verification_code($pdo, $adminId, $email, $name);

    json_out([
        'ok'         => true,
        'message'    => $sent
            ? 'Account created! Check your email for a verification code.'
            : 'Account created, but we could not send the email. Tap "Resend code" to try again.',
        'email_sent' => $sent,
    ], 201);

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        json_out([
            'ok'      => false,
            'message' => 'Email is already registered. Log in instead.',
            'errors'  => ['email' => 'Email is already registered. Log in instead.'],
        ], 409);
    }
    error_log('admin-register.php PDO error: ' . $e->getMessage());
    json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
} catch (Throwable $e) {
    error_log('admin-register.php error: ' . $e->getMessage());
    json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}