<?php
declare(strict_types=1);

/**
 * api/register.php — Customer registration (SRS FR-1)
 *
 * Accepts: POST JSON { firstName, surname, fullName, email, contact, password, confirm, website }
 * Returns: 201 { ok: true,  message, email_sent }          on success
 *          422 { ok: false, message, errors }               on validation failure
 *          409 { ok: false, message, errors, unverified? }   on duplicate email
 *          500 { ok: false, message }                       on server error
 *
 * Helpers (json_input, json_out, v_name, v_email, v_contact, v_password,
 * current_user) come from includes/helpers.php.
 * Email/code helpers come from includes/verification.php.
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/verification.php';

/* ---------- Already signed in? Send them home ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($me = current_user())) {
    header('Location: ' . redirect_path_for_role($me['role']));
    exit;
}

/* ---------- POST: create the account ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $in = json_input();

        // Hidden bot-trap field: pretend it worked so bots learn nothing.
        if (!empty($in['website'])) {
            json_out(['ok' => true, 'message' => 'Account created! Check your email for a verification code.']);
        }

        $firstName = trim((string)preg_replace('/\s+/u', ' ', (string)($in['firstName'] ?? '')));
        $surname   = trim((string)preg_replace('/\s+/u', ' ', (string)($in['surname'] ?? '')));

        if ($firstName !== '' || $surname !== '') {
            $name = trim($firstName . ' ' . $surname);
        } else {
            $name = trim((string)preg_replace('/\s+/u', ' ', (string)($in['fullName'] ?? '')));
        }

        $email    = strtolower(trim((string)($in['email'] ?? '')));
        $contact  = preg_replace('/\s/', '', (string)($in['contact'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $confirm  = (string)($in['confirm'] ?? '');

        // Each part is checked on its own (same rule as the page: letters and single spaces).
        // v_name() expects a FULL name, so it only runs on the combined result below.
        $partErr = static function (string $label, string $v): ?string {
            if ($v === '') {
                return "Enter your {$label}.";
            }
            if (!preg_match('/^\p{L}+(?: \p{L}+)*$/u', $v)) {
                return 'Letters only. No numbers or special characters.';
            }
            return null;
        };

        if ($firstName !== '' || $surname !== '') {
            $firstErr = $partErr('first name', $firstName);
            $lastErr  = $partErr('surname', $surname);
            // Safety net: the joined name must also pass the shared full-name rule.
            if ($firstErr === null && $lastErr === null) {
                $firstErr = v_name($name);
            }
            $nameErrors = ['firstName' => $firstErr, 'surname' => $lastErr];
        } else {
            // Old clients that only send fullName.
            $nameErrors = ['fullName' => v_name($name)];
        }

        $errors = array_filter($nameErrors + [
            'email'     => v_email($email),
            'contact'   => v_contact($contact),
            'password'  => v_password($password),
            'confirm'   => $password !== $confirm ? 'Passwords do not match.' : null,
        ]);

        if ($errors) {
            json_out([
                'ok'      => false,
                'message' => 'Please fix the highlighted fields.',
                'errors'  => $errors,
            ], 422);
        }

        $pdo = db();

        // Existing account? If it was never verified, let the page reopen the code popup.
        $st = $pdo->prepare('SELECT customer_id, email_verified_at FROM customer WHERE email = ?');
        $st->execute([$email]);
        if ($existing = $st->fetch()) {
            if ($existing['email_verified_at'] === null) {
                json_out([
                    'ok'         => false,
                    'unverified' => true,
                    'message'    => 'This email is registered but not verified yet.',
                ], 409);
            }
            json_out([
                'ok'      => false,
                'message' => 'Email is already registered. Log in instead.',
                'errors'  => ['email' => 'Email is already registered. Log in instead.'],
            ], 409);
        }

        $pdo->prepare(
            'INSERT INTO customer (full_name, email, contact_number, password_hash)
             VALUES (?, ?, ?, ?)'
        )->execute([$name, $email, $contact, password_hash($password, PASSWORD_DEFAULT)]);

        $customerId = (int)$pdo->lastInsertId();

        $sent = issue_verification_code($pdo, $customerId, $email, $name);

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