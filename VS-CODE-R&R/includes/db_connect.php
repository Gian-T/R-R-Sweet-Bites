<?php
declare(strict_types=1);

/**
 * R&R Sweet Bites - single shared connection + helpers.
 * Import rr_sweet_bites.sql in phpMyAdmin first.
 *
 * Settings can be overridden with environment variables (RR_DB_*),
 * otherwise the XAMPP defaults below are used.
 * If your MySQL runs on port 3307 or uses a different user, change the
 * defaults here or set the env vars.
 */
define('DB_HOST', getenv('RR_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('RR_DB_PORT') ?: 3307));
define('DB_NAME', getenv('RR_DB_NAME') ?: 'r&r sweet bites');
define('DB_USER', getenv('RR_DB_USER') ?: 'GianAdmin');
define('DB_PASS', getenv('RR_DB_PASSWORD') ?: 'password');

date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}



/* ---------- Connections ---------- */

/** PDO connection (preferred). Usage: db()->prepare(...) */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!extension_loaded('pdo_mysql')) {
            http_response_code(500);
            exit('The pdo_mysql PHP extension is not enabled. In XAMPP: open php.ini, make sure "extension=pdo_mysql" has no ";" in front, then restart Apache.');
        }
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    // Keep MySQL's NOW() in the same timezone as PHP (Manila, UTC+8)
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+08:00'",
                ]
            );
        } catch (PDOException $ex) {
            http_response_code(500);
            exit('Could not connect to the database. In XAMPP, make sure MySQL is started and that you imported rr_sweet_bites.sql in phpMyAdmin. Then check the DB_ settings at the top of db_connect.php.');
        }
    }
    return $pdo;
}

/** mysqli connection, only for the older JSON API code. Usage: $db = db_mysqli(); */
function db_mysqli(): mysqli
{
    static $mysqli = null;
    if ($mysqli === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            $mysqli->set_charset('utf8mb4');
            $mysqli->query("SET time_zone = '+08:00'");
        } catch (Exception $ex) {
            json_fail('Database connection failed.', 500);
        }
    }
    return $mysqli;
}

/* ---------- Output helpers ---------- */

/** HTML-escape. */
function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function peso($n): string
{
    return '₱' . number_format((float) $n, 2);
}

function fmt_date(?string $d): string
{
    return $d ? date('M j, Y', strtotime($d)) : '—';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function not_found(string $what): void
{
    http_response_code(404);
    exit('<!DOCTYPE html><meta charset="utf-8"><title>Not found</title>'
        . '<p style="font-family:sans-serif;padding:2rem">' . e($what) . ' not found.</p>');
}

/* ---------- JSON API helpers ---------- */

/** Call at the top of API endpoints (not globally, so HTML pages are unaffected). */
function json_header(): void
{
    header('Content-Type: application/json; charset=utf-8');
}

function json_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg]);
    exit;
}

// "RQ-2039" <-> 2039
function fmt_id($n): string   { return 'RQ-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT); }
function parse_id(string $s): int { return preg_match('/^RQ-(\d+)$/', $s, $m) ? (int) $m[1] : 0; }

// temporary: no login yet, so we act as customer #1 (Alexahe)
const CUSTOMER_ID = 1;

// database value -> text shown on your pages
const STATUS_LABEL = [
    'pending_review' => 'Pending Review', 'quoted' => 'Quoted',
    'confirmed' => 'Awaiting Downpayment',
    'downpayment_pending_verification' => 'Pending Verification',
    'in_production' => 'In Progress', 'out_for_delivery' => 'Out for Delivery',
    'ready_for_pickup' => 'Ready for Pickup', 'completed' => 'Completed',
    'cancellation_requested' => 'Cancellation Requested', 'cancelled' => 'Cancelled',
];
const PAY_STATUS = ['pending_verification' => 'Pending Verification', 'verified' => 'Verified', 'rejected' => 'Rejected'];
const PAY_TYPE   = ['downpayment' => '20% Downpayment', 'balance' => 'Balance'];
const PAY_METHOD = ['gcash' => 'GCash', 'bank_transfer' => 'Bank Transfer', 'cash' => 'Cash'];
const LEVEL      = ['low' => 'Low', 'medium' => 'Moderate', 'high' => 'Luxury'];
const CAKE_TYPE  = ['cake' => 'Cake', 'cupcake' => 'Cupcake', 'number_shaped_cake' => 'Number Shaped Cake'];

// price of the order = latest accepted quotation (alias the orders table as "o")
const PRICE_SQL = "COALESCE((SELECT q.quoted_price FROM quotation q
                   WHERE q.order_id = o.order_id AND q.status = 'accepted'
                   ORDER BY q.version_number DESC LIMIT 1), 0)";

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    if (empty($_SESSION['csrf'])) {
        return false;
    }
    $sent = $_POST['csrf']
         ?? $_SERVER['HTTP_X_CSRF_TOKEN']
         ?? '';
    return hash_equals($_SESSION['csrf'], (string) $sent);
}


/* ---------- Flash messages (survive one redirect) ---------- */
function flash_ok(string $msg): void
{
    $_SESSION['flash_ok'] = $msg;
}

function flash_errors(array $errors): void
{
    $_SESSION['flash_err'] = $errors;
}

function render_flash(string $extraStyle = ''): void
{
    $style = $extraStyle !== '' ? ' style="' . e($extraStyle) . '"' : '';
    if (!empty($_SESSION['flash_ok'])) {
        echo '<div class="flash ok" role="status"' . $style . '>' . e($_SESSION['flash_ok']) . '</div>';
    }
    if (!empty($_SESSION['flash_err'])) {
        echo '<div class="flash err" role="alert"' . $style . '><ul>';
        foreach ($_SESSION['flash_err'] as $m) {
            echo '<li>' . e($m) . '</li>';
        }
        echo '</ul></div>';
    }
    unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
}



/** Shared CSS for flash boxes, injected into each page's <style>. */
const FLASH_CSS = '.flash{margin:0 0 12px;padding:10px 14px;border-radius:8px;font-size:12px;line-height:1.4;border:1px solid;color:#4A2F2B}'
    . '.flash.ok{background:#E9EFE5;border-color:#829B7A}'
    . '.flash.err{background:#FBE9E9;border-color:#C65F62}'
    . '.flash ul{margin:0 0 0 16px}';