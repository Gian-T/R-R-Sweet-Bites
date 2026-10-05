<?php
declare(strict_types=1);

/**
 * R&R Sweet Bites — Log in (SRS FR-2)
 *
 * Works with the project's existing schema:
 *   customer(customer_id, full_name, email, contact_number, password_hash, ...)
 *   admin(admin_id, full_name, email, password_hash, ...)
 *   login_attempts(id, email, ip, attempted_at)
 *
 * Sets session keys on success so every other API in the project keeps working:
 *   $_SESSION['uid']          → the user's row ID
 *   $_SESSION['role']         → 'customer' | 'admin'
 *   $_SESSION['customer_id']  → the customer's ID (only when role = customer)
 *   $_SESSION['admin_id']     → the admin's ID    (only when role = admin)
 *
 * Helpers (json_input, json_out, client_ip, v_email, current_user) come from
 * includes/helpers.php — do NOT redefine them here.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

/* ---------- Constants specific to login ---------- */
const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 0;   // 5 minutes
const REMEMBER_DAYS      = 30;

/* ---------- Login-specific helper ---------- */

/** Look up an account across both tables. Returns null if neither table has it. */
function find_user(string $email): ?array
{
    // Try admin first — admins have their own table.
    $st = db()->prepare('SELECT admin_id AS id, "admin" AS role, full_name, password_hash FROM admin WHERE email = ?');
    $st->execute([$email]);
    if ($row = $st->fetch()) return $row;

    $st = db()->prepare('SELECT customer_id AS id, "customer" AS role, full_name, password_hash FROM customer WHERE email = ?');
    $st->execute([$email]);
    if ($row = $st->fetch()) return $row;

    return null;
}

/* ---------- Already signed in? Skip the form ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($me = current_user())) {
    header('Location: ' . ($me['role'] === 'admin' ? '../public/Weekly-Availability.html' : '../public/Customer_Orders.html'));
    exit;
}

/* ---------- POST: verify credentials ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in       = json_input();
    $email    = strtolower(trim((string)($in['email'] ?? '')));
    $password = (string)($in['password'] ?? '');
    $remember = !empty($in['remember']);
    $ip       = client_ip();
    $generic  = 'Invalid email or password.';   // never reveal which was wrong

    // Bot trap: this field is hidden from humans; bots fill it in.
    if (!empty($in['website'])) {
        json_out(['ok' => false, 'message' => $generic], 401);
    }
    if (v_email($email) !== null || $password === '' || strlen($password) > 64) {
        json_out(['ok' => false, 'message' => $generic], 401);
    }

    // Lock-out after too many failures
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

    // Look up the account, verify the password
    $user = find_user($email);
    if ($user) {
        $ok = password_verify($password, $user['password_hash']);
    } else {
        // Spend a similar amount of CPU so timing doesn't reveal whether the email exists
        password_hash($password, PASSWORD_DEFAULT);
        $ok = false;
    }

    if (!$ok) {
        db()->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([$email, $ip]);
        $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        json_out([
            'ok'      => false,
            'message' => $generic,
            'debug'   => $isLocal ? [
                'email_received'  => $email,
                'email_valid'     => v_email($email),
                'password_len'    => strlen($password),
                'website_filled'  => !empty($in['website']),
                'user_found'      => $user ? true : false,
                'user_id'         => $user['id'] ?? null,
                'user_role'       => $user['role'] ?? null,
                'hash_prefix'     => $user ? substr($user['password_hash'], 0, 7) : null,
                'hash_length'     => $user ? strlen($user['password_hash']) : null,
                'verify_result'   => $ok,
            ] : null,
        ], 401);
    }

    // Success
    db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $table = $user['role'] === 'admin' ? 'admin' : 'customer';
        $pk    = $user['role'] === 'admin' ? 'admin_id' : 'customer_id';
        db()->prepare("UPDATE `{$table}` SET password_hash = ? WHERE {$pk} = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_regenerate_id(true);   // stop session fixation
    $_SESSION['uid']  = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    if ($user['role'] === 'admin') {
        $_SESSION['admin_id'] = (int)$user['id'];
    } else {
        $_SESSION['customer_id'] = (int)$user['id'];
    }

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
        'role'      => $user['role'],
        'redirect'  => $user['role'] === 'admin' ? '../public/Weekly-Availability.html' : '../public/Customer_Orders.html',
    ]);
}
?>