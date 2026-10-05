<?php
declare(strict_types=1);

/**
 * api/register.php — Customer registration (SRS FR-1)
 *
 * Accepts: POST JSON { fullName, email, contact, password, confirm, website }
 * Returns: 201 { ok: true,  message }        on success
 *          422 { ok: false, message, errors } on validation failure
 *          409 { ok: false, message, errors } on duplicate email
 *          500 { ok: false, message }         on server error
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

/* ---------- Already signed in? Send them home ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($me = current_user())) {
    header('Location: ' . ($me['role'] === 'admin' ? '../public/Weekly-Availability.html' : '../public/Customer_Orders.html'));
    exit;
}

/* ---------- POST: create the account ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $in = json_input();

        // Hidden bot-trap field: pretend it worked so bots learn nothing.
        if (!empty($in['website'])) {
            json_out(['ok' => true, 'message' => 'Account created! You can now log in.']);
        }

        $name     = trim((string)preg_replace('/\s+/u', ' ', (string)($in['fullName'] ?? '')));
        $email    = strtolower(trim((string)($in['email'] ?? '')));
        $contact  = preg_replace('/\s/', '', (string)($in['contact'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $confirm  = (string)($in['confirm'] ?? '');

        $errors = array_filter([
            'fullName' => v_name($name),
            'email'    => v_email($email),
            'contact'  => v_contact($contact),
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
            'INSERT INTO customer (full_name, email, contact_number, password_hash)
             VALUES (?, ?, ?, ?)'
        )->execute([$name, $email, $contact, password_hash($password, PASSWORD_DEFAULT)]);

        json_out(['ok' => true, 'message' => 'Account created! You can now log in.'], 201);

    } catch (PDOException $e) {
        // 23000 = integrity constraint violation → duplicate email
        if ($e->getCode() === '23000') {
            json_out([
                'ok'      => false,
                'message' => 'Email is already registered. Log in instead.',
                'errors'  => ['email' => 'Email is already registered. Log in instead.'],
            ], 409);
        }
        error_log('register.php PDO error: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    } catch (Throwable $e) {
        error_log('register.php error: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
    }
}

// Any other method → 405
http_response_code(405);
header('Allow: POST');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);