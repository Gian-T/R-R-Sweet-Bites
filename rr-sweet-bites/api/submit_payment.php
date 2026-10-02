<?php
require 'db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only.', 405);

$oid    = parse_id($_POST['order_id'] ?? '');
$method = ['GCash' => 'gcash', 'Bank Transfer' => 'bank_transfer', 'Cash' => 'cash'][$_POST['method'] ?? ''] ?? null;
$ref    = strtoupper(trim($_POST['reference'] ?? ''));
if (!$method) fail('Invalid payment method.');

$st = $db->prepare("SELECT o.status, " . PRICE_SQL . " AS total,
  (SELECT COALESCE(SUM(p.amount),0) FROM payment p WHERE p.order_id = o.order_id AND p.status = 'verified') AS verified,
  (SELECT COUNT(*) FROM payment p WHERE p.order_id = o.order_id AND p.status = 'pending_verification') AS pending
  FROM `order` o WHERE o.order_id = ? AND o.customer_id = ?");
$st->bind_param('ii', $oid, $CUSTOMER_ID);
$st->execute();
$o = $st->get_result()->fetch_assoc();
if (!$o) fail('Order not found.', 404);
if ($o['total'] <= 0) fail('This order has no accepted quotation yet.');
if ($o['pending'] > 0) fail('You already have a payment waiting for verification.');

if ($o['status'] === 'confirmed') {
  $type = 'downpayment';
  $amount = round($o['total'] * 0.20, 2);
} elseif ($o['status'] === 'in_production' && $o['verified'] > 0) {
  $type = 'balance';
  $amount = round($o['total'] - $o['verified'], 2);
  if ($amount <= 0) fail('This order is already fully paid.');
} else {
  fail('This order cannot accept a payment right now.');
}

$proof = null;
if ($method === 'cash') {
  $ref = null;
} else {
  if (!preg_match('/^[A-Z0-9-]{8,20}$/', $ref)) fail('Reference must be 8–20 letters or numbers.');
  $f = $_FILES['proof'] ?? null;
  if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Please upload your proof of payment.');
  if ($f['size'] > 5 * 1024 * 1024) fail('File must be under 5 MB.');
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
  if (!$ext) fail('Only JPG or PNG files are allowed.');
  $dir = __DIR__ . '/../uploads';
  if (!is_dir($dir)) mkdir($dir, 0777, true);
  $name = bin2hex(random_bytes(8)) . '.' . $ext;
  move_uploaded_file($f['tmp_name'], "$dir/$name");
  $proof = 'uploads/' . $name;
}

$db->begin_transaction();
$ins = $db->prepare("INSERT INTO payment (order_id, payment_type, payment_method, reference_number, amount, proof_image_url, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending_verification')");
$ins->bind_param('isssds', $oid, $type, $method, $ref, $amount, $proof);
$ins->execute();
if ($type === 'downpayment') {
  $up = $db->prepare("UPDATE `order` SET status = 'downpayment_pending_verification' WHERE order_id = ?");
  $up->bind_param('i', $oid);
  $up->execute();
}
$db->commit();

echo json_encode(['ok' => true]);