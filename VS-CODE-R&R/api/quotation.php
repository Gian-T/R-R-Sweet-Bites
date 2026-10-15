<?php
declare(strict_types=1);

/**
 * api/quotation.php — Quotation worksheet endpoint (admin + customer)
 *
 * Status model (quotation.status):
 *   draft              → admin working copy, invisible to customer
 *   pending            → issued to customer, awaiting their response
 *   accepted           → customer accepted
 *   revision_requested → customer asked for changes
 *   revised            → superseded by a newer send
 *   rejected           → customer rejected (unused for now)
 *
 * Rule: only ONE row per order may be in status='pending' at any time.
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

function cake_type_label(string $t): string { return CAKE_TYPE[$t] ?? $t; }
function difficulty_label(string $d): string { return LEVEL[$d] ?? ucfirst($d); }

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
    // Only 'pending' quotes are shown to the customer.
    $st = db()->prepare(
        "SELECT o.order_id, o.status, o.cake_type, o.difficulty_level, o.design_description,
                q.quotation_id, q.quoted_price, q.version_number, q.status AS quotation_status,
                q.created_at AS quotation_created_at
           FROM `order` o
           LEFT JOIN quotation q
             ON q.quotation_id = (
                 SELECT quotation_id FROM quotation
                  WHERE order_id = o.order_id AND status = 'pending'
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
        "SELECT o.order_id, o.customer_id, o.cake_type, o.occasion, o.difficulty_level,
                o.design_description, o.preferred_date, o.status, o.is_rush,
                o.flavor, o.flavor_variant, o.frosting, o.filling, o.flavor_custom,
                o.num_layers, o.num_tiers, o.cake_height, o.cake_width,
                c.full_name, c.contact_number
           FROM `order` o
           JOIN customer c ON c.customer_id = o.customer_id
          WHERE o.order_id = ?"
    );
    $st->execute([$orderId]);
    $row = $st->fetch();
    if (!$row) return null;

    // All reference images
    $refImages = [];
    try {
        $img = db()->prepare('SELECT image_url FROM order_reference_image WHERE order_id = ? ORDER BY image_id');
        $img->execute([$orderId]);
        while ($u = $img->fetchColumn()) {
            if (!$u) continue;
            $clean = ltrim($u, '/');
            $clean = preg_replace('#^private/#', '', $clean);
            $refImages[] = '/VS-CODE-R&R/' . $clean;
        }
    } catch (Throwable $e) { /* ignore */ }

    // Delivery address
    $address = null;
    try {
        $fl = db()->prepare('SELECT delivery_address FROM fulfillment WHERE order_id = ? LIMIT 1');
        $fl->execute([$orderId]);
        $address = $fl->fetchColumn() ?: null;
    } catch (Throwable $e) { /* ignore */ }

    // Latest quotation (for Send/Revert buttons). Any status counts.
    $latest = null;
    try {
        $lq = db()->prepare(
            "SELECT quotation_id, version_number, status, quoted_price, remarks, created_at
               FROM quotation WHERE order_id = ?
              ORDER BY version_number DESC LIMIT 1"
        );
        $lq->execute([$orderId]);
        $latest = $lq->fetch() ?: null;
        if ($latest) {
            $latest['quotation_id']   = (int) $latest['quotation_id'];
            $latest['version_number'] = (int) $latest['version_number'];
            $latest['quoted_price']   = (float) $latest['quoted_price'];
            $latest['created_at_fmt'] = date('M j, Y, g:i A', strtotime($latest['created_at']));
        }
    } catch (Throwable $e) { /* ignore */ }

    // The currently-issued (pending) quote, if any
    $pending = null;
    try {
        $pq = db()->prepare(
            "SELECT quotation_id, version_number, quoted_price, remarks
               FROM quotation WHERE order_id = ? AND status = 'pending'
              ORDER BY version_number DESC LIMIT 1"
        );
        $pq->execute([$orderId]);
        $pending = $pq->fetch() ?: null;
        if ($pending) {
            $pending['quotation_id']   = (int) $pending['quotation_id'];
            $pending['version_number'] = (int) $pending['version_number'];
            $pending['quoted_price']   = (float) $pending['quoted_price'];
        }
    } catch (Throwable $e) { /* ignore */ }

    // Draft (if the admin has one saved and un-sent)
    $draft = null;
    try {
        $dq = db()->prepare(
            "SELECT quotation_id, version_number, quoted_price, remarks
               FROM quotation WHERE order_id = ? AND status = 'draft'
              ORDER BY version_number DESC LIMIT 1"
        );
        $dq->execute([$orderId]);
        $draft = $dq->fetch() ?: null;
        if ($draft) {
            $draft['quotation_id']   = (int) $draft['quotation_id'];
            $draft['version_number'] = (int) $draft['version_number'];
            $draft['quoted_price']   = (float) $draft['quoted_price'];
        }
    } catch (Throwable $e) { /* ignore */ }

    return [
        'is_rush'            => (bool) $row['is_rush'],
        'order_id'           => (int) $row['order_id'],
        'customer_id'        => (int) $row['customer_id'],
        'client_name'        => $row['full_name'],
        'client_phone'       => $row['contact_number'],
        'initials'           => initials($row['full_name']),
        'cake_type'          => $row['cake_type'],
        'cake_type_label'    => cake_type_label($row['cake_type']),
        'occasion'           => $row['occasion'],
        'occasion_label'     => ucfirst((string) $row['occasion']),
        'difficulty'         => $row['difficulty_level'],
        'difficulty_label'   => difficulty_label($row['difficulty_level']),
        'design_description' => $row['design_description'],
        'preferred_date'     => $row['preferred_date'],
        'delivery_address'   => $address,
        'status'             => $row['status'],
        'ref_image_url'      => $refImages[0] ?? null,
        'ref_image_urls'     => $refImages,
        'latest_quotation'   => $latest,
        'pending_quotation'  => $pending,
        'draft_quotation'    => $draft,
        'flavor'             => $row['flavor'],
        'flavor_variant'     => $row['flavor_variant'],
        'frosting'           => $row['frosting'],
        'filling'            => $row['filling'],
        'flavor_custom'      => $row['flavor_custom'],
        'num_layers'         => (int) $row['num_layers'],
        'num_tiers'          => (int) $row['num_tiers'],
        'cake_height'        => $row['cake_height'],
        'cake_width'         => $row['cake_width'],
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
        'draft'              => 'Draft',
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
            'label'          => $r['status'] === 'pending' ? 'Current' : ($r['status'] === 'draft' ? 'Draft' : 'Revision'),
            'created_at'     => date('M j, Y, g:i A', strtotime($r['created_at'])),
            'remarks'        => $r['remarks'] ?? '',
        ];
    }
    return $out;
}

function load_latest_customer_revision(int $orderId): ?array
{
    $st = db()->prepare(
        "SELECT r.message, r.created_at, r.quotation_id, q.version_number
           FROM quotation_revision r
           JOIN quotation q ON q.quotation_id = r.quotation_id
          WHERE r.order_id = ?
          ORDER BY r.revision_id DESC LIMIT 1"
    );
    $st->execute([$orderId]);
    $row = $st->fetch();
    return $row ? [
        'message'      => $row['message'],
        'created_at'   => date('M j, Y, g:i A', strtotime($row['created_at'])),
        'quotation_id' => (int) $row['quotation_id'],
        'version'      => (int) $row['version_number'],
    ] : null;
}

/**
 * Find the newest draft for an order. Returns null if none.
 */
function find_draft(PDO $pdo, int $orderId): ?array
{
    $st = $pdo->prepare(
        "SELECT quotation_id, quoted_price, remarks, version_number
           FROM quotation
          WHERE order_id = ? AND status = 'draft'
          ORDER BY version_number DESC LIMIT 1 FOR UPDATE"
    );
    $st->execute([$orderId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Find the pending (sent, awaiting customer) quote for an order. Returns null if none.
 */
function find_pending(PDO $pdo, int $orderId): ?array
{
    $st = $pdo->prepare(
        "SELECT quotation_id, quoted_price, remarks, version_number
           FROM quotation
          WHERE order_id = ? AND status = 'pending'
          ORDER BY version_number DESC LIMIT 1 FOR UPDATE"
    );
    $st->execute([$orderId]);
    $row = $st->fetch();
    return $row ?: null;
}

/* ---------- GET ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $action = $_GET['action'] ?? 'list';
    try {
        if ($action === 'list') {
            if ($role !== 'admin') json_out(['ok' => false, 'message' => 'Admin only.'], 403);
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

        if ($action === 'my') {
            if ($role !== 'customer') json_out(['ok' => false, 'message' => 'Customer only.'], 403);
            json_out(['ok' => true, 'orders' => load_orders_for_customer($custId)]);
        }

        if ($action === 'load') {
            $orderId = (int) ($_GET['order_id'] ?? 0);
            if ($orderId <= 0) json_out(['ok' => false, 'message' => 'Missing order_id.'], 400);

            $order = load_order_detail($orderId);
            if (!$order) json_out(['ok' => false, 'message' => 'Order not found.'], 404);

            if ($role === 'customer' && (int) $order['customer_id'] !== $custId) {
                json_out(['ok' => false, 'message' => 'Not your order.'], 403);
            }

            $me = null;
            if ($role === 'admin') {
                $st = db()->prepare('SELECT admin_id, full_name FROM admin WHERE admin_id = ?');
                $st->execute([$adminId]);
                $a = $st->fetch();
                $me = $a ? ['admin_id' => (int)$a['admin_id'], 'full_name' => $a['full_name'], 'initials' => initials($a['full_name'])] : null;
            }

            json_out([
                'ok'        => true,
                'csrf'      => csrf_token(),
                'me'        => $me,
                'order'     => $order,
                'revisions' => load_revisions($orderId),
                'customer_revision' => load_latest_customer_revision($orderId),
                'draft'     => ['lines' => [], 'prep' => '2 days', 'deposit' => '20% upfront payment', 'note' => ''],
            ]);
        }

        json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
    } catch (Throwable $e) {
        error_log('quotation.php GET: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
    }
}

/* ---------- POST ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $in = (stripos($contentType, 'application/json') === 0) ? json_input() : $_POST;

        $action  = (string) ($in['action'] ?? '');
        $orderId = (int)    ($in['order_id'] ?? 0);

        if ($orderId <= 0) json_out(['ok' => false, 'message' => 'Missing order_id.'], 400);

        $order = load_order_detail($orderId);
        if (!$order) json_out(['ok' => false, 'message' => 'Order not found.'], 404);

        /* ================= CUSTOMER ================= */
        if ($role === 'customer') {
            if ((int) $order['customer_id'] !== $custId) json_out(['ok' => false, 'message' => 'Not your order.'], 403);
            if (!csrf_ok()) json_out(['ok' => false, 'message' => 'Your session expired. Refresh and try again.'], 403);

            $st = db()->prepare(
                "SELECT quotation_id FROM quotation
                  WHERE order_id = ? AND status = 'pending'
                  ORDER BY version_number DESC LIMIT 1"
            );
            $st->execute([$orderId]);
            $qid = (int) $st->fetchColumn();
            if (!$qid) json_out(['ok' => false, 'message' => 'No pending quotation to respond to.'], 409);

            if ($action === 'accept') {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE quotation SET status = 'accepted' WHERE quotation_id = ?")->execute([$qid]);
                    $pdo->prepare("UPDATE `order` SET status = 'confirmed' WHERE order_id = ?")->execute([$orderId]);
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

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    // 1. Flip the quotation status
                    $pdo->prepare("UPDATE quotation SET status = 'revision_requested' WHERE quotation_id = ?")
                        ->execute([$qid]);

                    // 2. Persist the customer's message
                    $pdo->prepare(
                        "INSERT INTO quotation_revision (quotation_id, order_id, customer_id, message)
                         VALUES (?, ?, ?, ?)"
                    )->execute([$qid, $orderId, $custId, $msg]);

                    // 3. Notify the admin who issued the quote
                    $st = $pdo->prepare("SELECT admin_id FROM quotation WHERE quotation_id = ?");
                    $st->execute([$qid]);
                    $targetAdmin = (int) $st->fetchColumn();

                    if ($targetAdmin > 0) {
                        $note = "Customer requested a revision on order " . fmt_id($orderId) . ": " . mb_substr($msg, 0, 200);
                        $pdo->prepare(
                            "INSERT INTO notification (order_id, recipient_type, recipient_id, message)
                             VALUES (?, 'admin', ?, ?)"
                        )->execute([$orderId, $targetAdmin, mb_substr($note, 0, 500)]);
                    }

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }

                json_out(['ok' => true, 'message' => "Revision requested. We'll send an updated quote."]);
            }

            json_out(['ok' => false, 'message' => 'Unknown customer action.'], 400);
        }
        /* ================= /CUSTOMER ================= */

        /* ================= ADMIN ================= */
        if ($role === 'admin') {
            $lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];
            $note  = trim((string) ($in['note'] ?? ''));

            /* ---- Save as Draft ----
             *   - Refuses if a pending quote exists (must revert first).
             *   - If a draft already exists, updates it in place (no new version).
             *   - Otherwise inserts a new draft. */
            if ($action === 'save_draft') {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    if (find_pending($pdo, $orderId)) {
                        $pdo->rollBack();
                        json_out([
                            'ok' => false,
                            'message' => 'A quotation is already awaiting the customer. Revert it to draft first.',
                        ], 409);
                    }

                    $grand = 0.0;
                    foreach ($lines as $l) {
                        $grand += (float) ($l['qty'] ?? 0) * (float) ($l['unit_price'] ?? 0);
                    }

                    $existingDraft = find_draft($pdo, $orderId);
                    if ($existingDraft) {
                        $pdo->prepare(
                            "UPDATE quotation SET quoted_price = ?, remarks = ? WHERE quotation_id = ?"
                        )->execute([$grand, $note !== '' ? $note : null, $existingDraft['quotation_id']]);
                    } else {
                        $st = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) FROM quotation WHERE order_id = ?');
                        $st->execute([$orderId]);
                        $nextVersion = ((int) $st->fetchColumn()) + 1;

                        $pdo->prepare(
                            "INSERT INTO quotation (order_id, admin_id, quoted_price, remarks, version_number, status)
                             VALUES (?, ?, ?, ?, ?, 'draft')"
                        )->execute([$orderId, $adminId, $grand, $note !== '' ? $note : null, $nextVersion]);
                    }

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                json_out(['ok' => true, 'message' => 'Draft saved.']);
            }

            /* ---- Send Quotation ----
             *   - Refuses if a pending quote already exists.
             *   - If a draft exists, promotes it to 'pending'.
             *   - Otherwise inserts a new 'pending' row (walk-in quote, no draft step). */
            if ($action === 'send_quotation') {
                // Server-side price validation
                $custom = 0.0;
                $hasRushFee = false;
                foreach ($lines as $l) {
                    $desc  = (string) ($l['description'] ?? '');
                    $price = (float)  ($l['unit_price'] ?? 0);
                    if ($desc === 'Custom Order Price') $custom = $price;
                    if ($desc === 'Rush Order Fee' && $price > 0) $hasRushFee = true;
                }
                if ($custom <= 0) {
                    json_out(['ok' => false, 'message' => 'Enter a Custom Order Price greater than 0 before sending.'], 422);
                }
                if (!empty($order['is_rush']) && !$hasRushFee) {
                    json_out(['ok' => false, 'message' => 'This is a rush order. Please add a Rush Order Fee before sending.'], 422);
                }

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    if (find_pending($pdo, $orderId)) {
                        $pdo->rollBack();
                        json_out([
                            'ok' => false,
                            'message' => 'A quotation is already awaiting the customer. Revert it to draft first if you need to change it.',
                        ], 409);
                    }

                    $grand = 0.0;
                    foreach ($lines as $l) {
                        $grand += (float) ($l['qty'] ?? 0) * (float) ($l['unit_price'] ?? 0);
                    }

                    $existingDraft = find_draft($pdo, $orderId);
                    if ($existingDraft) {
                        $pdo->prepare(
                            "UPDATE quotation
                                SET quoted_price = ?, remarks = ?, status = 'pending'
                              WHERE quotation_id = ?"
                        )->execute([$grand, $note !== '' ? $note : null, $existingDraft['quotation_id']]);
                    } else {
                        $st = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) FROM quotation WHERE order_id = ?');
                        $st->execute([$orderId]);
                        $nextVersion = ((int) $st->fetchColumn()) + 1;

                        $pdo->prepare(
                            "INSERT INTO quotation (order_id, admin_id, quoted_price, remarks, version_number, status)
                             VALUES (?, ?, ?, ?, ?, 'pending')"
                        )->execute([$orderId, $adminId, $grand, $note !== '' ? $note : null, $nextVersion]);
                    }

                    // Move order into 'quoted' state
                    $pdo->prepare("UPDATE `order` SET status = 'quoted' WHERE order_id = ?")->execute([$orderId]);

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                json_out(['ok' => true, 'message' => 'Quotation sent to client.']);
            }

            /* ---- Revert to Draft ----
             *   - Demotes the current pending quote back to draft.
             *   - No-op if nothing is pending. */
            if ($action === 'revert_quotation') {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pending = find_pending($pdo, $orderId);
                    if (!$pending) {
                        $pdo->rollBack();
                        json_out(['ok' => false, 'message' => 'No sent quotation to revert.'], 409);
                    }

                    $pdo->prepare("UPDATE quotation SET status = 'draft' WHERE quotation_id = ?")
                        ->execute([$pending['quotation_id']]);

                    $pdo->prepare("UPDATE `order` SET status = 'pending_review' WHERE order_id = ?")
                        ->execute([$orderId]);

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                json_out(['ok' => true, 'message' => 'Quotation reverted to draft. The customer can no longer see it until you resend.']);
            }

            json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
        }
        /* ================= /ADMIN ================= */

        json_out(['ok' => false, 'message' => 'Unknown role.'], 403);

    } catch (Throwable $e) {
        error_log('quotation.php POST: ' . $e->getMessage());
        json_out(['ok' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
    }
}

http_response_code(405);
header('Allow: GET, POST');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);