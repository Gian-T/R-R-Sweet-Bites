<?php
declare(strict_types=1);
/**
 * order-confirmation.php — rebuilt against the live `r&r sweet bites` schema.
 * GET order-confirmation.php?order=RQ-0001
 */
require_once __DIR__ . '/../includes/db_connect.php';

json_header();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_fail('GET only.', 405);
}

$orderNo = trim((string) ($_GET['order'] ?? ''));
$pdo     = db();
$oid     = $orderNo !== '' ? parse_id($orderNo) : 0;

try {
    if ($oid > 0) {
        $stmt = $pdo->prepare(
            "SELECT o.order_id, o.design_description, o.preferred_date, o.status,
                    o.created_at, c.full_name,
                    " . PRICE_SQL . " AS total
             FROM `order` o
             JOIN customer c ON c.customer_id = o.customer_id
             WHERE o.order_id = ? AND o.customer_id = ?"
        );
        $stmt->execute([$oid, CUSTOMER_ID]);
    } else {
        // Fallback: most recent order for this customer
        $stmt = $pdo->prepare(
            "SELECT o.order_id, o.design_description, o.preferred_date, o.status,
                    o.created_at, c.full_name,
                    " . PRICE_SQL . " AS total
             FROM `order` o
             JOIN customer c ON c.customer_id = o.customer_id
             WHERE o.customer_id = ?
             ORDER BY o.order_id DESC LIMIT 1"
        );
        $stmt->execute([CUSTOMER_ID]);
    }
    $order = $stmt->fetch();
} catch (Throwable $e) {
    error_log('order-confirmation query failed: ' . $e->getMessage());
    json_fail('Could not load order.', 500);
}

if (!$order) {
    json_fail('Order not found.', 404);
}

// Payments — schema uses `payment`, status enum 'pending_verification'
$ps = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN status='verified'           THEN amount END), 0) AS verified,
            COALESCE(SUM(CASE WHEN status='pending_verification' THEN amount END), 0) AS pending
     FROM payment WHERE order_id = ?"
);
$ps->execute([$order['order_id']]);
$pay = $ps->fetch();

$total     = (float) $order['total'];
$dpPct     = 20;                              // SRS FR-16: fixed at 20%
$dpReq     = round($total * $dpPct / 100, 2);
$verified  = (float) $pay['verified'];
$pending   = (float) $pay['pending'];
$confirmed = $verified + 0.001 >= $dpReq;

if ($confirmed) {
    $paymentStatus = $dpPct . '% Deposit Received (' . peso($verified) . ')';
} elseif ($pending > 0) {
    $paymentStatus = 'Pending Verification (' . peso($pending) . ')';
} else {
    $paymentStatus = 'Awaiting ' . $dpPct . '% Deposit (' . peso(0) . ')';
}

// Map DB status → journey stage
$stageMap = [
    'pending_review'                     => 1,
    'quoted'                             => 1,
    'confirmed'                          => 1,
    'downpayment_pending_verification'   => 1,
    'in_production'                      => 2,
    'out_for_delivery'                   => 4,
    'ready_for_pickup'                   => 4,
    'completed'                          => 5,
];
$stage = $confirmed ? max($stageMap[$order['status']] ?? 1, 2) : 1;

$labels = [
    1 => 'Payment Verified',
    2 => 'In Production',
    3 => 'Quality Check',
    4 => 'Ready for Delivery/Pick up',
    5 => 'Delivered',
];
$steps = [];
foreach ($labels as $n => $label) {
    $steps[] = [
        'n'     => $n,
        'label' => $label,
        'state' => $n < $stage ? 'done' : ($n === $stage ? 'current' : ''),
    ];
}

// Initials: first letter of each word in full_name
$initials = '';
foreach (preg_split('/\s+/', trim($order['full_name'])) as $w) {
    if ($w !== '') $initials .= strtoupper($w[0]);
}

echo json_encode([  
    'client_initials'     => substr($initials, 0, 2),
    'crumb_ref'           => fmt_id($order['order_id']),
    'confirmed'           => $confirmed,
    'downpayment_percent' => $dpPct,
    'steps'               => $steps,
    'order_no'            => fmt_id($order['order_id']),
    'order_date'          => fmt_date($order['created_at']),
    'payment_status'      => $paymentStatus,
    'total'               => peso($total),
    'est_delivery_date'   => fmt_date($order['preferred_date']),
    'cake_style'          => $order['design_description'],
]);