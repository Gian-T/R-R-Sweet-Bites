<?php
declare(strict_types=1);

/**
 * R&R Sweet Bites — ADMIN log in, step 1 of 2
 * Same as login.php but only the `admin` table is searched, so customers can't use this page.
 *
 * Step 1 (this file):  email + password → a 6-digit code is emailed. NOT signed in yet.
 * Step 2:              api/login-verify.php  → code OK → session keys are set:
 *   $_SESSION['uid'], ['role'], ['customer_id'] or ['admin_id']
 * Resend:              api/login-resend.php
 *
 * Helpers (json_input, json_out, client_ip, v_email, current_user) come from
 * includes/helpers.php — do NOT redefine them here.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/login_code.php';

/* ---------- Constants specific to login ---------- */
const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 300;   // 5 minutes

/**
 * Look up an ADMIN account only.
 *
 * @return array{id:int, role:string, full_name:string, password_hash:string}|null
 */
function find_user(string $email): ?array
{
    $st = db()->prepare('SELECT admin_id AS id, "admin" AS role, full_name, password_hash FROM admin WHERE email = ?');
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

/* ---------- Already signed in? Skip the form ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($me = current_user())) {
    header('Location: ' . redirect_path_for_role($me['role']));
    exit;
}

/* ---------- POST: check the password, then email a code ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $pdo      = db();
        $in       = json_input();
        $email    = strtolower(trim((string)($in['email'] ?? '')));
        $password = (string)($in['password'] ?? '');
        $remember = !empty($in['remember']);
        $ip       = client_ip();
        $fail     = fn(int $code = 401, string $msg = 'Invalid email or password.') =>
                        json_out(['ok' => false, 'message' => $msg], $code);

        // Honeypot + basic validation
        if (!empty($in['website']) || v_email($email) !== null
            || $password === '' || strlen($password) > 64) {
            $fail();
        }

        // Lock-out after too many failures
        $st = $pdo->prepare(
            'SELECT COUNT(*) AS c,
                    ? - (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(MAX(attempted_at))) AS wait
               FROM login_attempts
              WHERE email = ? AND ip = ?
                AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
        );
        $st->execute([LOGIN_LOCK_SECONDS, $email, $ip]);
        $row = $st->fetch();
        if ((int)$row['c'] >= MAX_LOGIN_ATTEMPTS && (int)$row['wait'] > 0) {
            $fail(429, 'Too many attempts. Try again in ' . (int)$row['wait'] . ' seconds.');
        }

        // Verify password (dummy hash keeps timing similar when the email is unknown)
        $user = find_user($email);
        $ok   = $user
            ? password_verify($password, $user['password_hash'])
            : (password_hash($password, PASSWORD_DEFAULT) && false);
        error_log('user=' . json_encode($user) . ' verify=' . var_export($ok, true));

        if (!$ok) {
            $pdo->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([$email, $ip]);
            $fail();
        }


        $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $table = $user['role'] === 'admin' ? 'admin' : 'customer';
            $pk    = $user['role'] === 'admin' ? 'admin_id' : 'customer_id';
            $pdo->prepare("UPDATE `{$table}` SET password_hash = ? WHERE {$pk} = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        // Park the login until the emailed code is entered
        session_regenerate_id(true);
        unset($_SESSION['uid'], $_SESSION['role'], $_SESSION['customer_id'], $_SESSION['admin_id']);
        $_SESSION['pending_login'] = [
            'id' => (int)$user['id'], 'role' => $user['role'], 'email' => $email,
            'name' => $user['full_name'], 'remember' => $remember,
            'expires' => time() + LOGIN_PENDING_TTL_SECONDS,
        ];

        $status = login_code_status($pdo, $user['role'], (int)$user['id']);

        if ($status['age'] !== null && $status['age'] < VERIFY_RESEND_COOLDOWN_SECONDS) {
            $sent     = true;   // a code just went out (double-click): don't spam
            $cooldown = VERIFY_RESEND_COOLDOWN_SECONDS - $status['age'];
        } elseif ($status['recent'] >= VERIFY_RESEND_MAX_PER_HOUR) {
            unset($_SESSION['pending_login']);
            $fail(429, 'Too many login codes requested. Please try again in an hour.');
        } else {
            $sent     = issue_login_code($pdo, $user['role'], (int)$user['id'], $email, (string)$user['full_name']);
            $cooldown = VERIFY_RESEND_COOLDOWN_SECONDS;
        }

        json_out([
            'ok' => true, 'requires_code' => true,
            'email' => mask_email($email), 'email_sent' => $sent, 'cooldown' => $cooldown,
        ]);

    } catch (Throwable $e) {
        error_log('admin-login.php error: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
}


// Any other method → 405
http_response_code(405);
header('Allow: POST');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);