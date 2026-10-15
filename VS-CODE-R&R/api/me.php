<?php
// api/me.php - tells the shared site header who is logged in.
// Success: {"ok":true,"name":"Maria Santos","role":"customer","csrf":"..."}
// Not logged in: require_customer() answers with its usual 401 JSON (no "ok"),
// which the header treats as "logged out" and shows Log in / Sign up.
declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

json_header();
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Admin session: only admin_id is set, so handle it before require_customer().
$adminId = filter_var($_SESSION['admin_id'] ?? null, FILTER_VALIDATE_INT);
if ($adminId) {
    try {
        $db = db_mysqli();
        // ASSUMPTION: table `admin` with column `full_name`. Adjust to your schema.
        $st = $db->prepare('SELECT full_name FROM admin WHERE admin_id = ?');
        $st->bind_param('i', $adminId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();

        $name = $row ? trim((string) $row['full_name']) : '';
        if ($name === '') {
            $name = 'Admin'; // fall back rather than showing "logged out"
        }

        echo json_encode(
            ['ok' => true, 'name' => $name, 'role' => 'admin', 'csrf' => csrf_token()],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    } catch (Throwable $e) {
        error_log('me.php (admin) failed: ' . $e->getMessage());
        json_fail('Could not load account.', 500);
    }
}

// Customer path (unchanged apart from the added 'role' key).
$cid = require_customer();

try {
    $db = db_mysqli();
    $st = $db->prepare('SELECT full_name FROM customer WHERE customer_id = ?');
    $st->bind_param('i', $cid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();

    if (!$row || trim((string) $row['full_name']) === '') {
        json_fail('Not logged in.', 401);
    }

    echo json_encode(
        ['ok' => true, 'name' => trim((string) $row['full_name']), 'role' => 'customer', 'csrf' => csrf_token()],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    error_log('me.php failed: ' . $e->getMessage());
    json_fail('Could not load account.', 500);
}