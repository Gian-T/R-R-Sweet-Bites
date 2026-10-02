<?php
declare(strict_types=1);
/**
 * R&R Sweet Bites - shared settings and helpers.
 * Every page starts with:  require __DIR__ . '/config.php';
 */

// ---------- Database ----------
// This login is created for you by database.sql (the CREATE USER lines at the top).
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'rrcakes');
define('DB_USER', 'root');
define('DB_PASS', '');

// ---------- Other settings ----------
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);
define('MAX_LOGIN_ATTEMPTS', 5);   // failed logins within 15 minutes ...
define('LOGIN_LOCK_SECONDS', 60);  // ... then wait this long after the last one
define('REMEMBER_DAYS', 30);
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0');

// ---------- Session ----------
session_name('rrcakes_session');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true,
    'samesite' => 'Lax',                       // stops other websites from submitting forms as the user
    'secure'   => !empty($_SERVER['HTTPS']),
]);
if (session_status() === PHP_SESSION_NONE) session_start();

// ---------- Errors ----------
function is_ajax(): bool {
    return stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}
set_exception_handler(function (Throwable $e) {
    error_log('[RR] ' . $e);
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    // On your own computer the real reason is shown (e.g. "Unknown database"); online it is hidden.
    $msg = 'Server error. Please try again.' . ($local ? ' [' . $e->getMessage() . ']' : '');
    if (is_ajax()) json_out(['ok' => false, 'message' => $msg], 500);
    http_response_code(500);
    echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
});

// ---------- Database ----------
function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ---------- Request / response helpers ----------
function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Accepts only POST with a JSON body (a form on another website cannot send this). */
function json_input(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'message' => 'Method not allowed.'], 405);
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) json_out(['ok' => false, 'message' => 'Unsupported content type.'], 415);
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) json_out(['ok' => false, 'message' => 'Invalid request.'], 400);
    return $data;
}

function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

function current_user(): ?array {
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT id, role, full_name, email FROM users WHERE id = ?');
            $st->execute([$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
        }
    }
    return $user;
}

/** $asPage = true -> redirect to login (for pages). false -> JSON 401/403 (for background requests). */
function require_role(?string $role = null, bool $asPage = false): array {
    $u = current_user();
    if (!$u) {
        if ($asPage) { header('Location: login.php'); exit; }
        json_out(['ok' => false, 'message' => 'Please log in.'], 401);
    }
    if ($role !== null && $u['role'] !== $role) {
        if ($asPage) { http_response_code(403); exit('Access denied.'); }
        json_out(['ok' => false, 'message' => 'Access denied.'], 403);
    }
    return $u;
}

// ---------- Validation (the browser checks too, but the server never trusts it) ----------
function v_name(string $v): ?string {
    if ($v === '') return 'Enter your full name.';
    if (mb_strlen($v) < 3) return 'Name is too short.';
    if (mb_strlen($v) > 60) return 'Keep your name under 60 characters.';
    if (!preg_match("/^\p{L}[\p{L}\s.'’-]*$/u", $v)) return "Use letters, spaces, . ' - only.";
    if (count(array_filter(explode(' ', $v))) < 2) return 'Enter your first and last name.';
    if (preg_match('/(.)\1{3,}/iu', $v)) return 'That name looks incorrect.';
    return null;
}

function v_email(string $v): ?string {
    if ($v === '') return 'Enter your email address.';
    if (preg_match('/\s/', $v)) return "Email can't contain spaces.";
    if (strlen($v) > 254) return 'Email is too long.';
    $at = strrpos($v, '@');
    if ($at === false || $at < 1 || $at !== strpos($v, '@')) return 'Email needs one @, like you@email.com.';
    if ($at > 64) return 'Part before @ is too long.';
    if (str_contains($v, '..') || str_starts_with($v, '.') || str_contains($v, '.@')) return 'Remove extra dots in the email.';
    if (!filter_var($v, FILTER_VALIDATE_EMAIL) || !preg_match('/\.[A-Za-z]{2,}$/', $v)) return 'Enter a valid email, like you@email.com.';
    return null;
}

function v_contact(string $d): ?string {
    if ($d === '') return 'Enter your contact number.';
    if (!ctype_digit($d)) return 'Use numbers only.';
    if (!str_starts_with($d, '09')) return 'Number must start with 09.';
    if (strlen($d) !== 11) return 'Number must be 11 digits.';
    if (preg_match('/^09(\d)\1{8}$/', $d)) return 'Enter a real mobile number.';
    return null;
}

/** Password rule: at least 8 characters, one capital letter, one number. */
function v_password(string $v): ?string {
    if ($v === '') return 'Create a password.';
    if (strlen($v) < 8) return 'Use at least 8 characters.';
    if (strlen($v) > 64) return 'Use 64 characters or fewer.';
    if (!preg_match('/[A-Z]/', $v)) return 'Add one capital letter.';
    if (!preg_match('/\d/', $v)) return 'Add one number.';
    return null;
}
