<?php
require_once __DIR__ . '/../includes/db_connect.php';

json_header();
$db  = db_mysqli();
$cid = CUSTOMER_ID;   // bind_param needs a variable, not a constant

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('POST only.', 405);

// CSRF check — accept token from header or form body
if (!csrf_ok()) json_fail('Invalid or missing CSRF token.', 403);

$oid     = parse_id($_POST['order_id'] ?? '');
$reason  = $_POST['reason'] ?? '';
$details = mb_substr(trim($_POST['details'] ?? ''), 0, 300);
$pm      = ['GCash' => 'gcash', 'Bank Transfer' => 'bank_transfer'][$_POST['refund_method'] ?? ''] ?? null;
$acc     = $_POST['account_number'] ?? '';
$holder  = trim($_POST['account_holder'] ?? '');

$reasons = ['Change of plans', 'Event postponed or cancelled', 'Found another supplier', 'Price concerns', 'Other'];
if (!in_array($reason, $reasons, true)) json_fail('Please choose a reason.');
if (!$pm) json_fail('Invalid refund method.');
if ($pm === 'gcash' && !preg_match('/^09\d{9}$/', $acc)) json_fail('GCash number must be 11 digits starting with 09.');
if ($pm === 'bank_transfer' && !preg_match('/^\d{10,16}$/', $acc)) json_fail('Bank account number must be 10–16 digits.');
if (!preg_match('/^[A-Za-z\x{00C0}-\x{024F}][A-Za-z\x{00C0}-\x{024F}\s.\'-]{2,59}$/u', $holder)) json_fail('Invalid account holder name.');

$st = $db->prepare("SELECT status FROM `order` WHERE order_id = ? AND customer_id = ?");
$st->bind_param('ii', $oid, $cid);
$st->execute();
$o = $st->get_result()->fetch_assoc();
if (!$o) json_fail('Order not found.', 404);
if (!in_array($o['status'], ['confirmed', 'downpayment_pending_verification', 'in_production'], true))
  json_fail('This order can no longer be cancelled.');

try {
  $db->begin_transaction();
  $ins = $db->prepare("INSERT INTO cancellation (order_id, reason, details, refund_method, account_number, account_holder)
                      VALUES (?, ?, ?, ?, ?, ?)");
  $ins->bind_param('isssss', $oid, $reason, $details, $pm, $acc, $holder);
  $ins->execute();
  $up = $db->prepare("UPDATE `order` SET status = 'cancellation_requested' WHERE order_id = ?");
  $up->bind_param('i', $oid);
  $up->execute();
  $db->commit();

} catch (Throwable $e){
  $db->rollback();
    error_log('Cancel order failed: ' . $e->getMessage());
    json_fail('Could not submit cancellation request.', 500);
}


echo json_encode(['ok' => true]);