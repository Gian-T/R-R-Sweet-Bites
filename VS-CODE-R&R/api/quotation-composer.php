<?php
declare(strict_types=1);

/**
 * api/quotation.php — Quotation worksheet endpoint for the admin workspace.
 *
 * Requires: admin session ($_SESSION['admin_id'], $_SESSION['role'] === 'admin')
 *
 * GET  ?action=list
 *      ?action=load&order_id=42
 * POST { action: "save_draft" | "send_quotation", order_id, prep, deposit, note, lines[] }
 *
 * Response: JSON { ok: true|false, ... } with 200/4xx/5xx
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

/* ---------- Auth guard: admin only ---------- */
if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['admin_id'])) {
    json_out(['ok' => false, 'message' => 'Admin login required.'], 401);
}
$adminId = (int) $_SESSION['admin_id'];

/* ---------- Helpers ---------- */

function initials(string $name): string
{
    $parts = array_filter(preg_split('/\s+/', trim($name)));
    $parts = array_slice($parts, 0, 2);
    $out = '';
    foreach ($parts as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
    return $out ?: 'A';
}

function cake_type_label(string $t): string
{
    return CAKE_TYPE[$t] ?? $t;
}

function difficulty_label(string $d): string
{
    return LEVEL[$d] ?? ucfirst($d);
}

function status_label(string $s): string
{
    return STATUS_LABEL[$s] ?? $s;
}

function load_admin(int $id): ?array
{
    $st = db()->prepare('SELECT admin_id, full_name FROM admin WHERE admin_id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Returns orders in a sensible admin-view order. */
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

/** Loads the details for the "Request Reference" card. */
function load_order_detail(int $orderId): ?array
{
    $st = db()->prepare(
        "SELECT o.order_id, o.customer_id, o.cake_type, o.difficulty_level,
                o.design_description, o.design_intricacy_rating, o.status,
                c.full_name
           FROM `order` o
           JOIN customer c ON c.customer_id = o.customer_id
          WHERE o.order_id = ?"
    );
    $st->execute([$orderId]);
    $row = $st->fetch();
    if (!$row) return null;

    // Reference image (first, if any)
    $refImage = null;
    try {
        $img = db()->prepare('SELECT image_url FROM order_reference_image WHERE order_id = ? ORDER BY image_id LIMIT 1');
        $img->execute([$orderId]);
        $refImage = $img->fetchColumn() ?: null;
    } catch (Throwable $e) { /* ignore */ }

    return [
        'order_id'           => (int) $row['order_id'],
        'customer_id'        => (int) $row['customer_id'],
        'client_name'        => $row['full_name'],
        'initials'           => initials($row['full_name']),
        'cake_type'          => $row['cake_type'],
        'cake_type_label'    => cake_type_label($row['cake_type']),
        'difficulty'         => $row['difficulty_level'],
        'difficulty_label'   => difficulty_label($row['difficulty_level']),
        'design_description' => $row['design_description'],
        'status'             => $row['status'],
        'ref_image_url'      => $refImage,
    ];
}

/** Returns the list of quotations for the revision card. */
function load_revisions(int $orderId): array
{
    $st = db()->prepare(
        "SELECT quotation_id, quoted_price, version_number, status, created_at, remarks
           FROM quotation
          WHERE order_id = ?
          ORDER BY version_number DESC"
    );
    $st->execute([$orderId]);
    $rows = $st->fetchAll();

    // Friendly label per status
    $labels = [
        'pending'            => 'Draft',
        'accepted'           => 'Accepted',
        'revision_requested' => 'Client Rejected',
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
            'label'          => $r['status'] === 'pending' ? 'Current Draft' : 'Revision',
            'created_at'     => date('M j, Y, g:i A', strtotime($r['created_at'])),
        ];
    }
    return $out;
}

/* ---------- GET ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $action = $_GET['action'] ?? 'list';
    try {
        if ($action === 'list') {
            $admin = load_admin($adminId);
            json_out([
                'ok'     => true,
                'me'     => $admin ? [
                    'admin_id' => (int) $admin['admin_id'],
                    'full_name'=> $admin['full_name'],
                    'initials' => initials($admin['full_name']),
                ] : null,
                'orders' => load_orders(),
            ]);
        }

        if ($action === 'load') {
            $orderId = (int) ($_GET['order_id'] ?? 0);
            if ($orderId <= 0) json_out(['ok' => false, 'message' => 'Missing order_id.'], 400);

            $order = load_order_detail($orderId);
            if (!$order) json_out(['ok' => false, 'message' => 'Order not found.'], 404);

            $revisions = load_revisions($orderId);

            // Latest draft = latest version (which may be 'pending')
            $draft = [
                'lines'   => [],
                'prep'    => '2 days',
                'deposit' => '20% upfront payment',
                'note'    => '',
            ];
            if (!empty($revisions)) {
                $latest = $revisions[0];
                $draft['note'] = ''; // stored separately if you add a column; keep empty for now
            }

            json_out([
                'ok'        => true,
                'order'     => $order,
                'revisions' => $revisions,
                'draft'     => $draft,
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
        $in = json_input();
        $action  = (string) ($in['action'] ?? '');
        $orderId = (int)   ($in['order_id'] ?? 0);
        $send    = !empty($in['send']);
        $lines   = is_array($in['lines'] ?? null) ? $in['lines'] : [];
        $note    = trim((string) ($in['note'] ?? ''));
        $prep    = trim((string) ($in['prep'] ?? ''));
        $deposit = trim((string) ($in['deposit'] ?? ''));

        if ($orderId <= 0) json_out(['ok' => false, 'message' => 'Missing order_id.'], 400);
        if (!in_array($action, ['save_draft', 'send_quotation'], true)) {
            json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
        }

        // Verify order exists
        $order = load_order_detail($orderId);
        if (!$order) json_out(['ok' => false, 'message' => 'Order not found.'], 404);

        // Compute total
        $grand = 0.0;
        foreach ($lines as $l) {
            $qty   = (float) ($l['qty'] ?? 0);
            $price = (float) ($l['unit_price'] ?? 0);
            $grand += $qty * $price;
        }

        // Save a "pending" quotation row (one per version)
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Find the highest version for this order
            $st = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) FROM quotation WHERE order_id = ?');
            $st->execute([$orderId]);
            $nextVersion = ((int) $st->fetchColumn()) + 1;

            // Insert the new quotation row
            $ins = $pdo->prepare(
                "INSERT INTO quotation (order_id, admin_id, quoted_price, remarks, version_number, status)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([
                $orderId,
                $adminId,
                $grand,
                $note !== '' ? $note : null,
                $nextVersion,
                $send ? 'pending' : 'pending',   // both start pending; sending means "issuing"
            ]);

            // If sending: mark earlier pending rows as revised, and set order status to 'quoted'
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
            $pdo->rollBack();
            throw $e;
        }

        json_out([
            'ok'      => true,
            'message' => $send ? 'Quotation sent to client.' : 'Draft saved.',
        ]);

    } catch (Throwable $e) {
        error_log('quotation.php POST: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Server error.'], 500);
    }
}

/* ---------- Anything else ---------- */
http_response_code(405);
header('Allow: GET, POST');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);