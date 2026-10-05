<?php
require_once __DIR__ . '/../includes/db_connect.php';

json_header();
$db  = db_mysqli();
$cid = CUSTOMER_ID;   // bind_param needs a variable, not a constant
try {
  $st = $db->prepare("
    SELECT o.order_id, o.design_description, o.cake_type, o.preferred_date,
           o.difficulty_level, o.status, c.full_name,
           " . PRICE_SQL . " AS total,
           (SELECT q.quotation_id FROM quotation q
              WHERE q.order_id = o.order_id
              ORDER BY q.version_number DESC LIMIT 1) AS latest_quotation_id,
           (SELECT q.status FROM quotation q
              WHERE q.order_id = o.order_id
              ORDER BY q.version_number DESC LIMIT 1) AS quotation_status
    FROM `order` o
    JOIN customer c ON c.customer_id = o.customer_id
    WHERE o.customer_id = ?
    ORDER BY o.order_id DESC
");
$st->bind_param('i', $cid);
$st->execute();
$orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$ps = $db->prepare("SELECT created_at, payment_type, payment_method, reference_number, amount, status
                    FROM payment WHERE order_id = ? ORDER BY created_at, payment_id");

$out = [];
foreach ($orders as $o) {
  $ps->bind_param('i', $o['order_id']);
  $ps->execute();
  $pays = [];
  foreach ($ps->get_result() as $p) {
    $pays[] = [
      'date'   => date('Y-m-d', strtotime($p['created_at'])),
      'type'   => PAY_TYPE[$p['payment_type']]     ?? $p['payment_type'],
      'method' => PAY_METHOD[$p['payment_method']] ?? $p['payment_method'],
      'ref'    => $p['reference_number'] ?: '-',
      'amount' => (float) $p['amount'],
      'status' => PAY_STATUS[$p['status']]         ?? $p['status'],
    ];
  }
  $out[] = [
    'order_id'         => $o['order_id'],
    'id'               => fmt_id($o['order_id']),
    'title'            => $o['design_description'],
    'customer'         => $o['full_name'],
    'occasion'         => CAKE_TYPE[$o['cake_type']]         ?? $o['cake_type'],
    'total'            => (float) $o['total'],
    'level'            => LEVEL[$o['difficulty_level']]      ?? $o['difficulty_level'],
    'due'              => $o['preferred_date'] ? date('M d, Y', strtotime($o['preferred_date'])) : '-',
    'status'           => STATUS_LABEL[$o['status']]         ?? $o['status'],
    'quotation_status' => $o['quotation_status'],   // ← NEW
    'quotations_url'   => $o['latest_quotation_id'] ? 'quotation-accept.html' : null, // ← NEW
    'payments'         => $pays,
];
}
echo json_encode($out);
} catch (Throwable $e) {
    error_log('orders.php failed: ' . $e->getMessage());
    json_fail('Could not load orders.', 500);
}
