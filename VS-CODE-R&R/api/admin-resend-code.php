<?php
declare(strict_types=1);

/**
 * api/admin-resend-code.php — Send a new admin verification code.
 *
 * Accepts: POST JSON { email }
 * Returns: 200 { ok: true, message, cooldown }
 *          429 { ok: false, message, retry_after? }   too soon / too many
 *          502 { ok: false, message }                 email could not be sent
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

try {
    $in    = json_input();
    $email = strtolower(trim((string)($in['email'] ?? '')));

    if (v_email($email) !== null) {
        json_out(['ok' => false, 'message' => 'Enter a valid email address.'], 422);
    }

    $pdo = db();
    $okReply = [
        'ok'       => true,
        'message'  => 'A new code has been sent. Check your inbox (and spam folder).',
        'cooldown' => VERIFY_RESEND_COOLDOWN_SECONDS,
    ];

    $st = $pdo->prepare('SELECT admin_id, full_name, email_verified_at FROM admin WHERE email = ?');
    $st->execute([$email]);
    $c = $st->fetch();

    // Unknown or already-verified email: reply the same way so this can't be used to probe accounts.
    if (!$c || $c['email_verified_at'] !== null) {
        json_out($okReply);
    }

    $aid = (int)$c['admin_id'];

    // Rate limit (DB clock used for both sides, so timezones can't skew it).
    $st = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS age,
                SUM(created_at > (NOW() - INTERVAL 1 HOUR))   AS recent
         FROM admin_email_verifications WHERE admin_id = ?'
    );
    $st->execute([$aid]);
    $r = $st->fetch();

    if ($r['age'] !== null && (int)$r['age'] < VERIFY_RESEND_COOLDOWN_SECONDS) {
        $wait = VERIFY_RESEND_COOLDOWN_SECONDS - (int)$r['age'];
        json_out([
            'ok'          => false,
            'message'     => "Please wait {$wait} seconds before requesting another code.",
            'retry_after' => $wait,
        ], 429);
    }
    if ((int)$r['recent'] >= VERIFY_RESEND_MAX_PER_HOUR) {
        json_out(['ok' => false, 'message' => 'Too many codes requested. Please try again in an hour.'], 429);
    }

    $sent = issue_admin_verification_code($pdo, $aid, $email, (string)$c['full_name']);

    if (!$sent) {
        json_out(['ok' => false, 'message' => "We couldn't send the email right now. Please try again shortly."], 502);
    }

    json_out($okReply);

} catch (Throwable $e) {
    error_log('admin-resend-code.php error: ' . $e->getMessage());
    json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}