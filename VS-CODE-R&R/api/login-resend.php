<?php
declare(strict_types=1);

/**
 * api/login-resend.php — Send a new login code for the pending login.
 *
 * Accepts: POST (no body needed; the account comes from the server-side session)
 * Returns: 200 { ok: true, message, cooldown }
 *          401 { ok: false, message, restart: true }
 *          429 { ok: false, message, retry_after? }
 *          502 { ok: false, message }
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/login_code.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $p = login_pending();
    if (!$p) {
        json_out([
            'ok'      => false,
            'restart' => true,
            'message' => 'Your sign-in session expired. Please log in again.',
        ], 401);
    }

    $pdo    = db();
    $role   = (string)$p['role'];
    $uid    = (int)$p['id'];
    $status = login_code_status($pdo, $role, $uid);

    if ($status['age'] !== null && $status['age'] < VERIFY_RESEND_COOLDOWN_SECONDS) {
        $wait = VERIFY_RESEND_COOLDOWN_SECONDS - $status['age'];
        json_out([
            'ok'          => false,
            'message'     => "Please wait {$wait} seconds before requesting another code.",
            'retry_after' => $wait,
        ], 429);
    }
    if ($status['recent'] >= VERIFY_RESEND_MAX_PER_HOUR) {
        json_out(['ok' => false, 'message' => 'Too many codes requested. Please try again in an hour.'], 429);
    }

    $sent = issue_login_code($pdo, $role, $uid, (string)$p['email'], (string)$p['name']);
    if (!$sent) {
        json_out(['ok' => false, 'message' => "We couldn't send the email right now. Please try again shortly."], 502);
    }

    json_out([
        'ok'       => true,
        'message'  => 'A new code has been sent. Check your inbox (and spam folder).',
        'cooldown' => VERIFY_RESEND_COOLDOWN_SECONDS,
    ]);

} catch (Throwable $e) {
    error_log('login-resend.php error: ' . $e->getMessage());
    json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}