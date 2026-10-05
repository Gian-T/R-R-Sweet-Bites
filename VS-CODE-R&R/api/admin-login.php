<?php

declare(strict_types=1);

/**
 * R&R Sweet Bites — Admin log in (SRS FR-3)
 *
 * Same security guarantees as api/login.php, but restricted to the `admin` table.
 * A customer trying to log in here is rejected with the same generic message.
 *
 * On success, sets:
 *   $_SESSION['uid']       → admin_id
 *   $_SESSION['role']      → 'admin'
 *   $_SESSION['admin_id']  → admin_id
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 300;   // 5 minutes
const REMEMBER_DAYS      = 30;

/** Look up an admin by email. Returns null if not found. */
function find_admin(string $email): ?array
{
    $st = db()->prepare(
        'SELECT admin_id AS id, "admin" AS role, full_name, password_hash
           FROM admin WHERE email = ?'
    );
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

/* ---------- Already signed in as admin? Skip the form ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $me = current_user();
    if ($me && ($me['role'] ?? '') === 'admin') {
        header('Location: ../private/order-requests.html');
        exit;
    }
}

/* ---------- POST: verify admin credentials ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in       = json_input();
    $email    = strtolower(trim((string)($in['email'] ?? '')));
    $password = (string)($in['password'] ?? '');
    $remember = !empty($in['remember']);
    $ip       = client_ip();
    $generic  = 'Invalid email or password.';

    // Bot trap
    if (!empty($in['website'])) {
        json_out(['ok' => false, 'message' => $generic], 401);
    }
    if (v_email($email) !== null || $password === '' || strlen($password) > 64) {
        json_out(['ok' => false, 'message' => $generic], 401);
    }

    // Lock-out
    $st = db()->prepare(
        'SELECT COUNT(*) AS c,
                ? - (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(MAX(attempted_at))) AS wait
           FROM login_attempts
          WHERE email = ? AND ip = ?
            AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $st->execute([LOGIN_LOCK_SECONDS, $email, $ip]);
    $row = $st->fetch();
    if ((int)$row['c'] >= MAX_LOGIN_ATTEMPTS && (int)$row['wait'] > 0) {
        json_out(['ok' => false, 'message' => 'Too many attempts. Try again in ' . (int)$row['wait'] . ' seconds.'], 429);
    }

    // Look up — admin table only. Customers are simply "not found" here.
    $user = find_admin($email);
    if ($user) {
        $ok = password_verify($password, $user['password_hash']);
    } else {
        password_hash($password, PASSWORD_DEFAULT);   // constant-time defense
        $ok = false;
    }

    if (!$ok) {
        db()->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([$email, $ip]);
        json_out(['ok' => false, 'message' => $generic], 401);
    }

    // Success
    db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE admin SET password_hash = ? WHERE admin_id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_regenerate_id(true);   // stop session fixation
    $_SESSION['uid']      = (int)$user['id'];
    $_SESSION['role']     = 'admin';
    $_SESSION['admin_id'] = (int)$user['id'];
    // Clear any customer keys left over from a previous session
    unset($_SESSION['customer_id']);

    if ($remember) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + REMEMBER_DAYS * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
    }

    json_out([
        'ok'        => true,
        'firstName' => explode(' ', $user['full_name'])[0],
        'role'      => 'admin',
        'redirect'  => '../private/order-requests.html',
    ]);
}

/* ---------- Anything else → 405 ---------- */
http_response_code(405);
header('Allow: GET, POST');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
