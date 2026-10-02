<?php
require 'db.php';

$st = $db->prepare("SELECT o.order_id, o.design_description, o.cake_type, o.preferred_date,
                           o.difficulty_level, o.status, c.full_name, " . PRICE_SQL . " AS total
                    FROM `order` o JOIN customer c ON c.customer_id = o.customer_id
                    WHERE o.customer_id = ? ORDER BY o.order_id DESC");
$st->bind_param('i', $CUSTOMER_ID);
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
      'type'   => $PAY_TYPE[$p['payment_type']],
      'method' => $PAY_METHOD[$p['payment_method']],
      'ref'    => $p['reference_number'] ?: '-',
      'amount' => (float)$p['amount'],
      'status' => $PAY_STATUS[$p['status']],
    ];
  }
  $out[] = [
    'id' => fmt_id($o['order_id']), 'title' => $o['design_description'],
    'customer' => $o['full_name'], 'occasion' => $CAKE_TYPE[$o['cake_type']],
    'total' => (float)$o['total'], 'level' => $LEVEL[$o['difficulty_level']],
    'due' => date('M d, Y', strtotime($o['preferred_date'])),
    'status' => $STATUS_LABEL[$o['status']], 'payments' => $pays,
  ];
}
echo json_encode($out);