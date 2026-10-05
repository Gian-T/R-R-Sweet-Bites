<?php
declare(strict_types=1);

/**
 * Shared helpers for the JSON endpoints (login, register, etc.).
 * Required by login.php and register.php.
 */

/** Read the JSON request body as an associative array. Returns [] on garbage input. */
function json_input(): array
{
    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Emit a JSON response and stop. */
function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Visitor IP (best effort). */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* ---------- Field validators (return null if valid, or an error message) ---------- */

function v_name(string $name): ?string
{
    if ($name === '')                 return 'Enter your full name.';
    if (mb_strlen($name) < 3)         return 'Name is too short.';
    if (mb_strlen($name) > 60)        return 'Keep your name under 60 characters.';
    if (count(preg_split('/\s+/', trim($name))) < 2) return 'Enter your first and last name.';
    return null;
}

function v_email(string $email): ?string
{
    if ($email === '')             return 'Email is required.';
    if (strlen($email) > 254)      return 'Email is too long.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a valid email.';
    return null;
}

function v_contact(string $contact): ?string
{
    $d = preg_replace('/\D/', '', $contact);
    if ($d === '')              return 'Enter your contact number.';
    if (!str_starts_with($d, '09')) return 'Number must start with 09.';
    if (strlen($d) !== 11)      return 'Number must be 11 digits.';
    return null;
}

function v_password(string $password): ?string
{
    if ($password === '')          return 'Create a password.';
    if (strlen($password) < 8)     return 'Use at least 8 characters.';
    if (strlen($password) > 64)    return 'Use 64 characters or fewer.';
    if (!preg_match('/[A-Z]/', $password)) return 'Add one capital letter.';
    if (!preg_match('/\d/', $password))    return 'Add one number.';
    return null;
}

/* ---------- Session helpers ---------- */

/** Return the currently logged-in user (from customer or admin), or null. */
function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;

    if (($_SESSION['role'] ?? '') === 'admin') {
        $st = db()->prepare('SELECT admin_id AS id, "admin" AS role, full_name, email FROM admin WHERE admin_id = ?');
        $st->execute([$_SESSION['uid']]);
    } else {
        $st = db()->prepare('SELECT customer_id AS id, "customer" AS role, full_name, email FROM customer WHERE customer_id = ?');
        $st->execute([$_SESSION['uid']]);
    }
    return $st->fetch() ?: null;
}