<?php
declare(strict_types=1);
/**
 * Backend for order-confirmation.html
 *   GET order-confirmation.php?order=ORD-2024-0423  -> order data as JSON
 *   (falls back to a confirmed order when ?order= is missing)
 */
require __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = db();

/* ---------- Load order ---------- */
$orderNo = trim((string) ($_GET['order'] ?? ''));
$sql = 'SELECT o.*, c.initials AS client_initials, q.ref_no AS quote_ref
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        LEFT JOIN quotations q ON q.id = o.quotation_id ';
if ($orderNo !== '') {
    $st = $pdo->prepare($sql . 'WHERE o.order_no = ?');
    $st->execute([$orderNo]);
} else {
    $st = $pdo->query($sql . 'ORDER BY (o.stage >= 2) DESC, o.id ASC LIMIT 1');
}
$order = $st->fetch();
if (!$order) {
    out(['error' => 'Order not found.'], 404);
}

/* ---------- Payment figures ---------- */
$st = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN status = 'verified' THEN amount END), 0) AS verified,
            COALESCE(SUM(CASE WHEN status = 'pending'  THEN amount END), 0) AS pending
     FROM payments WHERE order_id = ?"
);
$st->execute([$order['id']]);
$pay = $st->fetch();

$total     = (float) $order['total_amount'];
$dpPct     = (int) $order['downpayment_percent'];
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

/* ---------- Order journey tracker ---------- */
// orders.stage = the step currently in progress (1-5); 6 = every step finished.
// Until the downpayment is verified the tracker stays on step 1.
$stage = $confirmed ? max((int) $order['stage'], 2) : 1;
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

out([
    'client_initials'    => $order['client_initials'],
    'crumb_ref'          => $order['quote_ref'] ?? $order['order_no'],
    'confirmed'          => $confirmed,
    'downpayment_percent' => $dpPct,
    'steps'              => $steps,
    'order_no'           => $order['order_no'],
    'order_date'         => fmt_date($order['order_date']),
    'payment_status'     => $paymentStatus,
    'total'              => peso($total),
    'est_delivery_date'  => fmt_date($order['est_delivery_date']),
    'cake_style'         => $order['cake_style'],
]);