<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db_connect.php';

// Backend for admin_payment_verification.html (list, approve/decline, show images).
// Uses the tables and helpers from db_connect.php / R_Rschema_mysql.sql.
ini_set('display_errors', '0');
defined('UPLOAD_DIR') || define('UPLOAD_DIR', __DIR__ . '/uploads');   // where customer images are stored

// TEMPORARY, same idea as CUSTOMER_ID in db_connect.php: until there is an admin login,
// act as the admin with this admin_id. Once login sets $_SESSION['admin_id'], change this to 0.
const TEMP_ADMIN_ID = 1;

/* ---------- Helpers ---------- */
function json_reply(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('[RR payment-verification] ' . $e);
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    json_reply(['ok' => false, 'message' => 'Server error. Please try again.' . ($local ? ' [' . $e->getMessage() . ']' : '')], 500);
});

function read_json(): array {
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) json_reply(['ok' => false, 'message' => 'Unsupported content type.'], 415);
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) json_reply(['ok' => false, 'message' => 'Invalid request.'], 400);
    return $data;
}

function require_admin(): array {
    $id = (int)($_SESSION['admin_id'] ?? TEMP_ADMIN_ID);
    if ($id > 0) {
        $st = db()->prepare('SELECT admin_id, full_name FROM admin WHERE admin_id = ?');
        $st->execute([$id]);
        if ($row = $st->fetch()) return $row;
    }
    json_reply(['ok' => false, 'message' => 'Please log in as an admin.'], 401);
}

function pay_no($id): string { return 'PAY-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); }

/** Images stored as a URL are used directly; stored files are shown through this script. */
function image_link(int $paymentId, string $kind, ?string $stored): ?string {
    if (!$stored) return null;
    if (preg_match('#^https?://#i', $stored)) return $stored;
    return 'admin-payment-verification.php?file=' . $paymentId . '&kind=' . $kind . '&v=' . md5($stored);
}

function serve_image(?string $stored): never {
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $stored = trim((string)$stored);
    $ext    = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
    $base   = realpath(UPLOAD_DIR);
    $path   = false;
    if ($stored !== '' && $base !== false && isset($types[$ext])) {
        // the saved value may be "uploads/abc.jpg" (relative to the site) or just "abc.jpg"
        foreach ([__DIR__ . '/' . ltrim($stored, '/\\'), $base . '/' . basename(str_replace('\\', '/', $stored))] as $try) {
            $real = realpath($try);
            if ($real !== false && is_file($real) && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) { $path = $real; break; }
        }
    }
    if ($path === false) { http_response_code(404); exit; }
    header('Content-Type: ' . $types[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function payment_sql(): string {
    return 'SELECT p.payment_id, p.order_id, p.payment_type, p.payment_method, p.amount, p.proof_image_url, p.status, p.remarks, p.created_at,
                   o.cake_type, o.design_description, o.flavor, o.num_layers, o.num_tiers, o.preferred_date, o.is_rush,
                   c.full_name, c.email,
                   ' . PRICE_SQL . ' AS quoted_price,
                   (SELECT r.image_url FROM order_reference_image r WHERE r.order_id = o.order_id ORDER BY r.image_id LIMIT 1) AS ref_image_url
              FROM payment p
              JOIN `order` o ON o.order_id = p.order_id
              JOIN customer c ON c.customer_id = o.customer_id';
}

/** Converts a database row into what the page's JavaScript expects. */
function map_payment(array $r): array {
    $quote  = (float)$r['quoted_price'];
    $dp     = round($quote * 0.20, 2);
    $method = PAY_METHOD[$r['payment_method']] ?? $r['payment_method'];
    $layers = (int)$r['num_layers'];
    $tiers  = (int)$r['num_tiers'];
    $id     = (int)$r['payment_id'];
    $out = [
        'key'           => (string)$id,
        'rq'            => fmt_id($r['order_id']),
        'orderNo'       => (string)$r['order_id'],
        'name'          => $r['full_name'],
        'email'         => $r['email'],
        'desc'          => (PAY_TYPE[$r['payment_type']] ?? $r['payment_type']) . ' · ' . $method,
        'method'        => $method,
        'ptype'         => ucfirst($r['payment_type']),
        'amount'        => (float)$r['amount'],
        'pay'           => pay_no($id),
        'submitted'     => substr((string)$r['created_at'], 0, 10),
        'status'        => $r['status'] === 'pending_verification' ? 'pending' : $r['status'],
        'remarks'       => $r['remarks'] ?? '',
        'requestedDate' => date('M d, Y', strtotime((string)$r['preferred_date'])),
        'cakeSize'      => (CAKE_TYPE[$r['cake_type']] ?? $r['cake_type']) . ' · ' . $layers . ' layer' . ($layers === 1 ? '' : 's') . ' · ' . $tiers . ' tier' . ($tiers === 1 ? '' : 's'),
        'flavor'        => $r['flavor'],
        'design'        => $r['design_description'],
        'rush'          => (bool)$r['is_rush'],
        'proofLabel'    => $method . ' proof of payment',
        'basePrice'     => $quote,
        'calc'          => [['Downpayment Due (20%)', peso($dp)], ['Balance Remaining', peso($quote - $dp)]],
    ];
    if ($url = image_link($id, 'ref', $r['ref_image_url']))    { $out['refImage']   = $url; $out['refFileName']   = basename((string)$r['ref_image_url']); }
    if ($url = image_link($id, 'proof', $r['proof_image_url'])) { $out['proofImage'] = $url; $out['proofFileName'] = basename((string)$r['proof_image_url']); }
    return $out;
}

function load_stats(): array {
    $row = db()->query(
        "SELECT COALESCE(SUM(status = 'pending_verification'), 0) AS pending,
                COALESCE(SUM(status = 'verified' AND DATE(verified_at) = CURDATE()), 0) AS verified_today,
                COALESCE(SUM(status = 'rejected'), 0) AS rejected,
                COALESCE(SUM(CASE WHEN status = 'verified' AND YEARWEEK(verified_at, 1) = YEARWEEK(NOW(), 1) THEN amount END), 0) AS total_week
           FROM payment"
    )->fetch();
    return ['pending' => (int)$row['pending'], 'verifiedToday' => (int)$row['verified_today'],
            'rejected' => (int)$row['rejected'], 'totalWeek' => (float)$row['total_week']];
}

/* ---------- Requests ---------- */
$admin  = require_admin();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Who is logged in (fills the name/avatar in the page header)
if ($method === 'GET' && ($_GET['action'] ?? '') === 'me') {
    $initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(array_filter(explode(' ', $admin['full_name'])), 0, 2))));
    json_reply(['ok' => true, 'name' => $admin['full_name'], 'initials' => $initials]);
}

// Show an uploaded image
if ($method === 'GET' && isset($_GET['file'])) {
    $kind = $_GET['kind'] ?? '';
    if (!in_array($kind, ['ref', 'proof'], true)) { http_response_code(400); exit; }
    $st = db()->prepare('SELECT p.proof_image_url AS proof_url,
                                (SELECT r.image_url FROM order_reference_image r WHERE r.order_id = p.order_id ORDER BY r.image_id LIMIT 1) AS ref_url
                           FROM payment p WHERE p.payment_id = ?');
    $st->execute([(int)$_GET['file']]);
    $row = $st->fetch();
    serve_image($row[$kind === 'proof' ? 'proof_url' : 'ref_url'] ?? null);
}

// Load the list + stats
if ($method === 'GET' && ($_GET['action'] ?? '') === 'list') {
    $rows = db()->query(payment_sql() . " ORDER BY p.status = 'pending_verification' DESC, p.created_at DESC")->fetchAll();
    json_reply(['ok' => true, 'payments' => array_map('map_payment', $rows), 'stats' => load_stats()]);
}

// Approve / decline
if ($method === 'POST') {
    $in = read_json();
    $token = (string)($in['csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        json_reply(['ok' => false, 'message' => 'Invalid CSRF token.'], 403);
    }
    if (($in['action'] ?? '') !== 'decide') json_reply(['ok' => false, 'message' => 'Unknown action.'], 400);

    $id       = (int)($in['id'] ?? 0);
    $decision = (string)($in['decision'] ?? '');
    $note     = trim((string)($in['note'] ?? ''));
    if (!in_array($decision, ['verified', 'rejected'], true)) json_reply(['ok' => false, 'message' => 'Invalid decision.'], 422);
    if ($decision === 'rejected' && $note === '') json_reply(['ok' => false, 'message' => 'Add a note so the customer knows what to fix.'], 422);
    if (mb_strlen($note) > 300) json_reply(['ok' => false, 'message' => 'Note must be 300 characters or fewer.'], 422);

    $pdo = db();
    $pdo->beginTransaction();   // payment, order and notification change together, or not at all
    try {
        $st = $pdo->prepare('SELECT p.payment_id, p.status, p.payment_type, p.order_id, o.customer_id, o.status AS order_status
                               FROM payment p JOIN `order` o ON o.order_id = p.order_id
                              WHERE p.payment_id = ? FOR UPDATE');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) { $pdo->rollBack(); json_reply(['ok' => false, 'message' => 'Payment not found.'], 404); }
        if ($p['status'] !== 'pending_verification') { $pdo->rollBack(); json_reply(['ok' => false, 'message' => 'This payment was already reviewed.'], 409); }

        $pdo->prepare('UPDATE payment SET status = ?, remarks = ?, verified_by_admin_id = ?, verified_at = NOW() WHERE payment_id = ?')
            ->execute([$decision, $note !== '' ? $note : null, $admin['admin_id'], $id]);

        // Only a downpayment moves the order: verified -> in progress, rejected -> back to awaiting downpayment.
        if ($p['payment_type'] === 'downpayment' && $p['order_status'] === 'downpayment_pending_verification') {
            $pdo->prepare('UPDATE `order` SET status = ? WHERE order_id = ?')
                ->execute([$decision === 'verified' ? 'in_production' : 'confirmed', $p['order_id']]);
        }

        $what = $p['payment_type'] === 'downpayment' ? 'downpayment' : 'balance payment';
        $msg  = $decision === 'verified'
            ? "Your {$what} for order " . fmt_id($p['order_id']) . ' was verified. Thank you!'
            : "Your {$what} for order " . fmt_id($p['order_id']) . " was rejected: {$note}";
        $pdo->prepare("INSERT INTO notification (order_id, recipient_type, recipient_id, message) VALUES (?, 'customer', ?, ?)")
            ->execute([$p['order_id'], $p['customer_id'], mb_substr($msg, 0, 500)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $st = $pdo->prepare(payment_sql() . ' WHERE p.payment_id = ?');
    $st->execute([$id]);
    json_reply(['ok' => true, 'payment' => map_payment($st->fetch()), 'stats' => load_stats()]);
}

json_reply(['ok' => false, 'message' => 'Bad request.'], 400);