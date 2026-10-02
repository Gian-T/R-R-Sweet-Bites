<?php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $db = new mysqli('localhost', 'root', '', 'rr_sweet_bites');
  $db->set_charset('utf8mb4');
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database connection failed.']);
  exit;
}

$CUSTOMER_ID = 1;   // temporary: no login yet, so we act as customer #1 (Alexahe)

function fail($msg, $code = 400) {
  http_response_code($code);
  echo json_encode(['error' => $msg]);
  exit;
}

// "RQ-2039" <-> 2039
function fmt_id($n)   { return 'RQ-' . str_pad($n, 4, '0', STR_PAD_LEFT); }
function parse_id($s) { return preg_match('/^RQ-(\d+)$/', $s, $m) ? (int)$m[1] : 0; }

// database value -> text shown on your pages
$STATUS_LABEL = [
  'pending_review' => 'Pending Review', 'quoted' => 'Quoted',
  'confirmed' => 'Awaiting Downpayment',
  'downpayment_pending_verification' => 'Pending Verification',
  'in_production' => 'In Progress', 'out_for_delivery' => 'Out for Delivery',
  'ready_for_pickup' => 'Ready for Pickup', 'completed' => 'Completed',
  'cancellation_requested' => 'Cancellation Requested', 'cancelled' => 'Cancelled',
];
$PAY_STATUS = ['pending_verification' => 'Pending Verification', 'verified' => 'Verified', 'rejected' => 'Rejected'];
$PAY_TYPE   = ['downpayment' => '20% Downpayment', 'balance' => 'Balance'];
$PAY_METHOD = ['gcash' => 'GCash', 'bank_transfer' => 'Bank Transfer', 'cash' => 'Cash'];
$LEVEL      = ['low' => 'Low', 'medium' => 'Moderate', 'high' => 'Luxury'];
$CAKE_TYPE  = ['cake' => 'Cake', 'cupcake' => 'Cupcake', 'number_shaped_cake' => 'Number Shaped Cake'];

// price of the order = latest accepted quotation
const PRICE_SQL = "COALESCE((SELECT q.quoted_price FROM quotation q
                   WHERE q.order_id = o.order_id AND q.status = 'accepted'
                   ORDER BY q.version_number DESC LIMIT 1), 0)";