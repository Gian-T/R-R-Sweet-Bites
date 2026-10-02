<?php
declare(strict_types=1);

/**
 * R&R Sweet Bites - shared configuration.
 * Uses MySQL / MariaDB (XAMPP). Import rr_sweet_bites.sql in phpMyAdmin first.
 */
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'rr_sweet_bites';
const DB_USER = 'root';   // XAMPP default
const DB_PASS = '';       // XAMPP default (empty)

date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
            exit('Could not connect to the database. In XAMPP, make sure MySQL is started and that you imported rr_sweet_bites.sql in phpMyAdmin. Then check the DB_ settings at the top of config.php.');
        }
    }
    return $pdo;
}

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
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
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

/** Shared CSS for flash boxes, injected into each page's <style>. */
const FLASH_CSS = '.flash{margin:0 0 12px;padding:10px 14px;border-radius:8px;font-size:12px;line-height:1.4;border:1px solid;color:#4A2F2B}'
    . '.flash.ok{background:#E9EFE5;border-color:#829B7A}'
    . '.flash.err{background:#FBE9E9;border-color:#C65F62}'
    . '.flash ul{margin:0 0 0 16px}';
