<?php
declare(strict_types=1);

/**
 * api/csrf.php — hands the current CSRF token to admin pages that need
 * to POST to the availability save endpoint.
 *
 * Contract:
 *   GET  → 200 { ok: true, csrf: "..." }        when an admin is logged in
 *        → 401 { ok: false, message: "..." }    otherwise
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Admin login required.']);
    exit;
}

echo json_encode(['ok' => true, 'csrf' => csrf_token()]);