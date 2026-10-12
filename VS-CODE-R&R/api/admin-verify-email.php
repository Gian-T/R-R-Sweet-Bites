<?php
declare(strict_types=1);

/**
 * api/admin-verify-email.php — Check the 6-digit admin registration code.
 *
 * Accepts: POST JSON { email, code }
 * Returns: 200 { ok: true }                      verified (or already verified)
 *          422 { ok: false, message }             wrong / missing code
 *          410 { ok: false, message }             code expired
 *          429 { ok: false, message }             too many wrong attempts
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
    $code  = preg_replace('/\D/', '', (string)($in['code'] ?? ''));

    if ($email === '' || strlen($code) !== 6) {
        json_out(['ok' => false, 'message' => 'Enter the 6-digit code from your email.'], 422);
    }

    $pdo = db();

    $st = $pdo->prepare('SELECT admin_id, email_verified_at FROM admin WHERE email = ?');
    $st->execute([$email]);
    $admin = $st->fetch();

    if (!$admin) {
        json_out(['ok' => false, 'message' => 'Incorrect code. Please try again.'], 422);
    }
    if ($admin['email_verified_at'] !== null) {
        json_out(['ok' => true, 'message' => 'Email already verified.']);
    }

    $aid = (int)$admin['admin_id'];

    // Only the newest code counts.
    $st = $pdo->prepare(
        'SELECT id, code_hash, expires_at, attempts
         FROM admin_email_verifications WHERE admin_id = ? ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$aid]);
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
        $pdo->prepare('UPDATE admin_email_verifications SET attempts = attempts + 1 WHERE id = ?')
            ->execute([$row['id']]);
        $left = max(0, VERIFY_MAX_ATTEMPTS - ((int)$row['attempts'] + 1));
        json_out([
            'ok'      => false,
            'message' => $left > 0
                ? "Incorrect code. {$left} attempt" . ($left === 1 ? '' : 's') . ' left.'
                : 'Too many wrong attempts. Tap "Resend code" to get a new one.',
        ], 422);
    }

    // Correct: mark verified and clean up.
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE admin SET email_verified_at = NOW() WHERE admin_id = ?')->execute([$aid]);
    $pdo->prepare('DELETE FROM admin_email_verifications WHERE admin_id = ?')->execute([$aid]);
    $pdo->commit();

    json_out(['ok' => true, 'message' => 'Email verified. You can now log in.']);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('admin-verify-email.php error: ' . $e->getMessage());
    json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}