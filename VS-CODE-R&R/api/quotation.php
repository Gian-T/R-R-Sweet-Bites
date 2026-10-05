<?php
declare(strict_types=1);

/**
 * api/quotation.php — Worksheet endpoint for the admin quotation composer
 *                     AND customer-facing quotation viewer/acceptor.
 *
 * Roles:
 *   - admin  : full access (list, load any order, save draft, send quotation)
 *   - customer : load only their own orders; accept or request revision
 *
 * GET  ?action=list                              (admin)
 *      ?action=load&order_id=42                   (admin, or customer owning order)
 *      ?action=my                                 (customer — list own orders with quotations)
 * POST { action: "save_draft"|"send_quotation", ... }   (admin)
 * POST { action: "accept"|"revision", order_id, message? }  (customer)
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

/* ---------- Auth: admin OR customer ---------- */
$role    = $_SESSION['role']        ?? '';
$adminId = (int) ($_SESSION['admin_id']    ?? 0);
$custId  = (int) ($_SESSION['customer_id'] ?? 0);

if ($role !== 'admin' && $role !== 'customer') {
    json_out(['ok' => false, 'message' => 'Login required.'], 401);
}

/* ---------- Helpers ---------- */

function initials(string $name): string
{
    $parts = array_slice(array_values(array_filter(preg_split('/\s+/', trim($name)))), 0, 2);
    $out = '';
    foreach ($parts as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
    return $out ?: 'A';
}

function load_orders(): array
{
    $st = db()->query(
        "SELECT o.order_id, o.status, o.cake_type, o.difficulty_level, o.design_description
           FROM `order` o
          WHERE o.status IN ('pending_review','quoted','confirmed','in_production','completed')
          ORDER BY FIELD(o.status, 'pending_review','quoted','confirmed','in_production','completed'),
                   o.order_id DESC"
    );
    return $st->fetchAll();
}

function load_orders_for_customer(int $custId): array
{
    $st = db()->prepare(
        "SELECT o.order_id, o.status, o.cake_type, o.difficulty_level, o.design_description,
                q.quotation_id, q.quoted_price, q.version_number, q.status AS quotation_status,
                q.created_at AS quotation_created_at
           FROM `order` o
           LEFT JOIN quotation q
             ON q.quotation_id = (
                 SELECT quotation_id FROM quotation
                  WHERE order_id = o.order_id
                  ORDER BY version_number DESC LIMIT 1
             )
          WHERE o.customer_id = ?
          ORDER BY o.order_id DESC"
    );
    $st->execute([$custId]);
    return $st->fetchAll();
}

function load_order_detail(int $orderId): ?array
{
    $st = db()->prepare(
        "SELECT o.order_id, o.customer_id, o.cake_type, o.difficulty_level,
                o.design_description, o.preferred_date, o.status,
                c.full_name
           FROM `order` o
           JOIN customer c ON c.customer_id = o.customer_id
          WHERE o.order_id = ?"
    );
    $st->execute([$orderId]);
    $row = $st->fetch();
    if (!$row) return null;

    $refImage = null;
    try {
        $img = db()->prepare('SELECT image_url FROM order_reference_image WHERE order_id = ? ORDER BY image_id LIMIT 1');
        $img->execute([$orderId]);
        $refImage = $img->fetchColumn() ?: null;
    } catch (Throwable $e) {}

    return [
        'order_id'           => (int) $row['order_id'],
        'customer_id'        => (int) $row['customer_id'],
        'client_name'        => $row['full_name'],
        'initials'           => initials($row['full_name']),
        'cake_type'          => $row['cake_type'],
        'cake_type_label'    => CAKE_TYPE[$row['cake_type']] ?? $row['cake_type'],
        'difficulty'         => $row['difficulty_level'],
        'difficulty_label'   => LEVEL[$row['difficulty_level']] ?? ucfirst($row['difficulty_level']),
        'design_description' => $row['design_description'],
        'preferred_date'     => $row['preferred_date'],
        'status'             => $row['status'],
        'ref_image_url'      => $refImage,
    ];
}

function load_revisions(int $orderId): array
{
    $st = db()->prepare(
        "SELECT quotation_id, quoted_price, version_number, status, created_at, remarks
           FROM quotation WHERE order_id = ? ORDER BY version_number DESC"
    );
    $st->execute([$orderId]);
    $rows = $st->fetchAll();

    $labels = [
        'pending'            => 'Awaiting Customer',
        'accepted'           => 'Accepted',
        'revision_requested' => 'Revision Requested',
        'revised'            => 'Revised',
        'rejected'           => 'Rejected',
    ];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'quotation_id'   => (int) $r['quotation_id'],
            'quoted_price'   => (float) $r['quoted_price'],
            'version_number' => (int) $r['version_number'],
            'status'         => $r['status'],
            'status_label'   => $labels[$r['status']] ?? ucfirst($r['status']),
            'label'          => $r['status'] === 'pending' ? 'Current' : 'Revision',
            'created_at'     => date('M j, Y, g:i A', strtotime($r['created_at'])),
            'remarks'        => $r['remarks'] ?? '',
        ];
    }
    return $out;
}

/* ---------- GET ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $action = $_GET['action'] ?? 'list';
    try {
        /* -- Admin: list all orders -- */
        if ($action === 'list') {
            if ($role !== 'admin') {
                json_out(['ok' => false, 'message' => 'Admin only.'], 403);
            }
            $st = db()->prepare('SELECT admin_id, full_name FROM admin WHERE admin_id = ?');
            $st->execute([$adminId]);
            $admin = $st->fetch();
            json_out([
                'ok'     => true,
                'me'     => $admin ? [
                    'admin_id'  => (int) $admin['admin_id'],
                    'full_name' => $admin['full_name'],
                    'initials'  => initials($admin['full_name']),
                ] : null,
                'orders' => load_orders(),
            ]);
        }

        /* -- Customer: list own orders with quotation summary -- */
        if ($action === 'my') {
            if ($role !== 'customer') {
                json_out(['ok' => false, 'message' => 'Customer only.'], 403);
            }
            json_out(['ok' => true, 'orders' => load_orders_for_customer($custId)]);
        }

        /* -- Load one order + its quotations (admin, or owning customer) -- */
        if ($action === 'load') {
            $orderId = (int) ($_GET['order_id'] ?? 0);
            if ($orderId <= 0) json_out(['ok' => false, 'message' => 'Missing order_id.'], 400);

            $order = load_order_detail($orderId);
            if (!$order) json_out(['ok' => false, 'message' => 'Order not found.'], 404);

            if ($role === 'customer' && (int) $order['customer_id'] !== $custId) {
                json_out(['ok' => false, 'message' => 'Not your order.'], 403);
            }

            json_out([
            'ok'        => true,
            'csrf'      => csrf_token(),   // ← ADD THIS
            'order'     => $order,
            'revisions' => load_revisions($orderId),
            'draft'     => ['lines' => [], 'prep' => '2 days', 'deposit' => '20% upfront payment', 'note' => ''],
        ]);
        }

        json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
    } catch (Throwable $e) {
        error_log('quotation.php GET: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Server error.'], 500);
    }
}

/* ---------- POST ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        // Accept both JSON and form-encoded (customer-accept uses FormData)
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === 0) {
            $in = json_input();
        } else {
            $in = $_POST;
        }

        $action  = (string) ($in['action'] ?? '');
        $orderId = (int)    ($in['order_id'] ?? 0);

        if ($orderId <= 0) json_out(['ok' => false, 'message' => 'Missing order_id.'], 400);

        $order = load_order_detail($orderId);
        if (!$order) json_out(['ok' => false, 'message' => 'Order not found.'], 404);

        /* ---------- Customer actions ---------- */
        if ($role === 'customer') {
            // Ownership + CSRF
            if ((int) $order['customer_id'] !== $custId) {
                json_out(['ok' => false, 'message' => 'Not your order.'], 403);
            }
            if (!csrf_ok()) {
                json_out(['ok' => false, 'message' => 'Your session expired. Refresh and try again.'], 403);
            }

            // Find the latest 'pending' quotation for this order
            $st = db()->prepare(
                "SELECT quotation_id FROM quotation
                  WHERE order_id = ? AND status = 'pending'
                  ORDER BY version_number DESC LIMIT 1"
            );
            $st->execute([$orderId]);
            $qid = (int) $st->fetchColumn();
            if (!$qid) {
                json_out(['ok' => false, 'message' => 'No pending quotation to respond to.'], 409);
            }

            if ($action === 'accept') {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE quotation SET status = 'accepted' WHERE quotation_id = ?")
                        ->execute([$qid]);
                    $pdo->prepare("UPDATE `order` SET status = 'confirmed' WHERE order_id = ?")
                        ->execute([$orderId]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                json_out(['ok' => true, 'message' => 'Quotation accepted. Thank you!']);
            }

             if ($action === 'revision') {
                $msg = trim((string) ($in['message'] ?? ''));
                $len = mb_strlen($msg);
                if ($len < 5)    json_out(['ok' => false, 'message' => 'Please describe the change (min 5 characters).'], 422);
                if ($len > 1000) json_out(['ok' => false, 'message' => 'Keep it under 1000 characters.'], 422);

                db()->prepare("UPDATE quotation SET status = 'revision_requested' WHERE quotation_id = ?")
                    ->execute([$qid]);

                json_out(['ok' => true, 'message' => 'Revision requested. We\'ll send an updated quote.']);
            }

            json_out(['ok' => false, 'message' => 'Unknown customer action.'], 400);
        }

        /* ---------- Admin actions ---------- */
        if ($role === 'admin') {
            $lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];
            $note  = trim((string) ($in['note'] ?? ''));
            $send  = !empty($in['send']);

            if (!in_array($action, ['save_draft', 'send_quotation'], true)) {
                json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
            }

            $grand = 0.0;
            foreach ($lines as $l) {
                $qty   = (float) ($l['qty'] ?? 0);
                $price = (float) ($l['unit_price'] ?? 0);
                $grand += $qty * $price;
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) FROM quotation WHERE order_id = ?');
                $st->execute([$orderId]);
                $nextVersion = ((int) $st->fetchColumn()) + 1;

                $ins = $pdo->prepare(
                    "INSERT INTO quotation (order_id, admin_id, quoted_price, remarks, version_number, status)
                     VALUES (?, ?, ?, ?, ?, 'pending')"
                );
                $ins->execute([$orderId, $adminId, $grand, $note !== '' ? $note : null, $nextVersion]);

                if ($send) {
                    $pdo->prepare(
                        "UPDATE quotation SET status = 'revised'
                          WHERE order_id = ? AND quotation_id <> LAST_INSERT_ID() AND status = 'pending'"
                    )->execute([$orderId]);

                    $pdo->prepare("UPDATE `order` SET status = 'quoted' WHERE order_id = ?")
                        ->execute([$orderId]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            json_out([
                'ok'      => true,
                'message' => $send ? 'Quotation sent to client.' : 'Draft saved.',
            ]);
        }

        json_out(['ok' => false, 'message' => 'Unknown role.'], 403);

    } catch (Throwable $e) {
        error_log('quotation.php POST: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Server error.'], 500);
    }
}

/* ---------- Fallback ---------- */
http_response_code(405);
header('Allow: GET, POST');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);