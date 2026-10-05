<?php
declare(strict_types=1);

/**
 * api/order-requests.php — Dashboard data for the admin order-requests page.
 * Requires: admin session ($_SESSION['admin_id'], $_SESSION['role'] === 'admin')
 *   GET ?action=list  → { ok, me, orders: [...] }
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['admin_id'])) {
    json_out(['ok' => false, 'message' => 'Admin login required.'], 401);
}
$adminId = (int) $_SESSION['admin_id'];

/* ---------- Helpers ---------- */

function initials(string $name): string
{
    $parts = array_slice(array_values(array_filter(preg_split('/\s+/', trim($name)))), 0, 2);
    $out = '';
    foreach ($parts as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
    return $out ?: 'A';
}

function difficulty_label(string $d): string
{
    return LEVEL[$d] ?? ucfirst($d);
}

function occasion_label(string $cakeType): string
{
    return CAKE_TYPE[$cakeType] ?? ucfirst($cakeType);
}

/* ---------- GET ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $action = $_GET['action'] ?? 'list';
    if ($action !== 'list') {
        json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
    }

    try {
        $me = null;
        $st = db()->prepare('SELECT admin_id, full_name FROM admin WHERE admin_id = ?');
        $st->execute([$adminId]);
        if ($row = $st->fetch()) {
            $me = [
                'admin_id'  => (int) $row['admin_id'],
                'full_name' => $row['full_name'],
                'initials'  => initials($row['full_name']),
            ];
        }

        $st = db()->query(
            "SELECT o.order_id, o.status, o.cake_type, o.difficulty_level,
                    o.design_description, o.preferred_date, o.is_rush, o.created_at,
                    c.full_name, c.email
               FROM `order` o
               JOIN customer c ON c.customer_id = o.customer_id
              ORDER BY FIELD(o.status,
                             'pending_review','quoted','confirmed',
                             'in_production','completed','cancelled'),
                       o.preferred_date ASC,
                       o.order_id DESC"
        );

        $orders = [];
        foreach ($st->fetchAll() as $row) {
            $orders[] = [
                'order_id'           => (int) $row['order_id'],
                'status'             => $row['status'],
                'cake_type'          => $row['cake_type'],
                'difficulty'         => $row['difficulty_level'],
                'difficulty_label'   => difficulty_label($row['difficulty_level']),
                'design_description' => $row['design_description'],
                'preferred_date'     => $row['preferred_date'],
                'is_rush'            => (bool) $row['is_rush'],
                'created_at'         => $row['created_at'],
                'client_name'        => $row['full_name'],
                'initials'           => initials($row['full_name']),
                'email'              => $row['email'],
                'occasion'           => occasion_label($row['cake_type']),
            ];
        }

        json_out(['ok' => true, 'me' => $me, 'orders' => $orders]);

    } catch (Throwable $e) {
        error_log('order-requests.php: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Server error.'], 500);
    }
}

http_response_code(405);
header('Allow: GET');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);