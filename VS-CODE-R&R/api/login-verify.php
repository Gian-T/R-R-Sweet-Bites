<?php
declare(strict_types=1);

/**
 * api/login-verify.php — Log in, step 2 of 2: check the emailed code.
 *
 * Accepts: POST JSON { code }   (the account comes from the server-side session, not the browser)
 * Returns: 200 { ok: true, firstName, role, redirect }
 *          401 { ok: false, message, restart: true }  no pending login / expired → log in again
 *          410 / 422 / 429 { ok: false, message }     expired / wrong / too many tries
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
    $in   = json_input();
    $code = preg_replace('/\D/', '', (string)($in['code'] ?? ''));

    $p = login_pending();
    if (!$p) {
        json_out([
            'ok'      => false,
            'restart' => true,
            'message' => 'Your sign-in session expired. Please log in again.',
        ], 401);
    }

    if (strlen($code) !== 6) {
        json_out(['ok' => false, 'message' => 'Enter the 6-digit code from your email.'], 422);
    }

    $pdo  = db();
    $role = (string)$p['role'];
    $uid  = (int)$p['id'];

    // Only the newest code counts.
    $st = $pdo->prepare(
        'SELECT id, code_hash, expires_at, attempts
         FROM login_codes WHERE role = ? AND user_id = ? ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$role, $uid]);
    $row = $st->fetch();

    if (!$row) {
        json_out(['ok' => false, 'message' => 'No active code. Tap "Resend code" to get a new one.'], 422);
    }
    if (new DateTimeImmutable($row['expires_at']) < new DateTimeImmutable('now')) {
        json_out(['ok' => false, 'message' => 'This code has expired. Tap "Resend code" to get a new one.'], 410);
    }
    if ((int)$row['attempts'] >= VERIFY_MAX_ATTEMPTS) {
        json_out(['ok' => false, 'message' => 'Too many wrong attempts. Tap "Resend code" to get a new one.'], 429);
    }

    if (!password_verify($code, $row['code_hash'])) {
        $pdo->prepare('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        $left = max(0, VERIFY_MAX_ATTEMPTS - ((int)$row['attempts'] + 1));
        json_out([
            'ok'      => false,
            'message' => $left > 0
                ? "Incorrect code. {$left} attempt" . ($left === 1 ? '' : 's') . ' left.'
                : 'Too many wrong attempts. Tap "Resend code" to get a new one.',
        ], 422);
    }

    // Correct code: clean up, and (customers) treat the email as verified too.
    $pdo->prepare('DELETE FROM login_codes WHERE role = ? AND user_id = ?')->execute([$role, $uid]);
    if ($role === 'customer') {
        $pdo->prepare('UPDATE customer SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE customer_id = ?')
            ->execute([$uid]);
    } elseif ($role === 'admin') {
        $pdo->prepare('UPDATE admin SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE admin_id = ?')
            ->execute([$uid]);
    }

    complete_login($p);

    json_out([
        'ok'        => true,
        'firstName' => explode(' ', trim((string)$p['name']))[0],
        'role'      => $role,
        'redirect'  => redirect_path_for_role($role),
    ]);

} catch (Throwable $e) {
    error_log('login-verify.php error: ' . $e->getMessage());
    json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}