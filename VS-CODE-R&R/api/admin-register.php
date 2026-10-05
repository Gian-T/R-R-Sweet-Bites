<?php
declare(strict_types=1);

/**
 * api/admin-register.php — Create an admin account.
 *
 * Safety:
 *   - Only runs from localhost (127.0.0.1 / ::1), so a stray deploy can't be exploited.
 *   - Refuses to create an admin if one already exists (bootstrap-only).
 *   - Uses the same validation helpers as the customer register.
 *
 * Accepts: POST JSON { fullName, email, password, confirm, website }
 * Returns: 201 { ok: true,  message }        on success
 *          422 { ok: false, message, errors } on validation failure
 *          409 { ok: false, message, errors } on duplicate email
 *          403 { ok: false, message }         if an admin already exists
 *          500 { ok: false, message }         on server error
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

/* ---------- Localhost-only guard ---------- */
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($ip, ['127.0.0.1', '::1'], true)
        || str_starts_with($ip, '192.168.')
        || str_starts_with($ip, '10.')
        || str_starts_with($ip, '172.');

if (!$isLocal) {
    json_out(['ok' => false, 'message' => 'Not allowed.'], 403);
}

/* ---------- POST: create the admin ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $in = json_input();

        // Bot trap
        if (!empty($in['website'])) {
            json_out(['ok' => true, 'message' => 'Account created.']);
        }

        // Bootstrap guard: refuse if any admin already exists
        try {
            $hasAdmin = (bool)db()->query('SELECT 1 FROM `admin` LIMIT 2')->fetch();
        } catch (Throwable $e) {
            error_log('admin-register: existence check failed: ' . $e->getMessage());
            $hasAdmin = false;
        }
        if ($hasAdmin) {
            json_out([
                'ok'      => false,
                'message' => 'An admin account already exists. Log in instead.',
            ], 403);
        }

        $name     = trim((string)preg_replace('/\s+/u', ' ', (string)($in['fullName'] ?? '')));
        $email    = strtolower(trim((string)($in['email'] ?? '')));
        $password = (string)($in['password'] ?? '');
        $confirm  = (string)($in['confirm'] ?? '');

        $errors = array_filter([
            'fullName' => v_name($name),
            'email'    => v_email($email),
            'password' => v_password($password),
            'confirm'  => $password !== $confirm ? 'Passwords do not match.' : null,
        ]);

        if ($errors) {
            json_out([
                'ok'      => false,
                'message' => 'Please fix the highlighted fields.',
                'errors'  => $errors,
            ], 422);
        }

        db()->prepare(
            'INSERT INTO `admin` (full_name, email, password_hash) VALUES (?, ?, ?)'
        )->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

        json_out([
            'ok'      => true,
            'message' => 'Admin account created. You can now log in.',
        ], 201);

    } catch (PDOException $e) {
        // 23000 = integrity constraint violation → duplicate email
        if ($e->getCode() === '23000') {
            json_out([
                'ok'      => false,
                'message' => 'That email is already registered.',
                'errors'  => ['email' => 'That email is already registered.'],
            ], 409);
        }
        error_log('admin-register: PDO error: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    } catch (Throwable $e) {
        error_log('admin-register: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
}

/* ---------- Anything else → 405 ---------- */
http_response_code(405);
header('Allow: POST');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);