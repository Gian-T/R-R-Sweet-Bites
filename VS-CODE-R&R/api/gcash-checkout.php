<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db_connect.php';
require __DIR__ . '/../includes/payments.php';

header('Content-Type: application/json; charset=utf-8');

/* EDIT THIS: path of your customer order page, relative to APP_URL */
const ORDER_PAGE_PATH = '/public/customer-orders-PaymentAndBalance.html';

function fail(string $msg, int $code): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only.', 405);

$pdo        = db();
$customerId = require_customer();
if (!csrf_ok()) fail('Your session expired. Please reload the page.', 419);

$orderId = filter_input(INPUT_POST, 'order', FILTER_VALIDATE_INT);
if (!$orderId) fail('Order not found.', 404);

$st = $pdo->prepare(
    'SELECT o.*, q.quoted_price
     FROM `order` o
     LEFT JOIN quotation q ON q.order_id = o.order_id AND q.status = "accepted"
     WHERE o.order_id = ? AND o.customer_id = ?'
);
$st->execute([$orderId, $customerId]);
$order = $st->fetch();
if (!$order) fail('Order not found.', 404);

$fig  = order_figures($pdo, $order);
$mode = pay_mode($fig, $order);
if (!in_array($mode, ['downpayment', 'balance'], true)) fail('Nothing to pay right now.', 409);

$amount = $mode === 'downpayment' ? $fig['dp_required'] : $fig['remaining'];
$base   = rtrim(env('APP_URL'), '/');
$secret = env('PAYMONGO_SECRET_KEY');
if ($secret === '' || $base === '') fail('Payment is not configured.', 500);

$orderNo = 'ORD-' . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT);

$body = ['data' => ['attributes' => [
    'line_items' => [[
        'currency' => 'PHP',
        'amount'   => (int) round($amount * 100),   // centavos
        'name'     => ucfirst($mode) . ' - ' . $orderNo,
        'quantity' => 1,
    ]],
    'payment_method_types' => ['gcash'],
    'success_url' => $base . ORDER_PAGE_PATH . '?order=' . $orderId . '&paid=1',
    'cancel_url'  => $base . ORDER_PAGE_PATH . '?order=' . $orderId,
    'description' => $orderNo,
    'metadata'    => ['order_id' => (string) $orderId, 'payment_type' => $mode],
]]];

$ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => $secret . ':',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_TIMEOUT        => 20,
]);
$raw  = curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$res = json_decode((string) $raw, true);

if ($http >= 300 || empty($res['data']['id'])) {
    error_log('paymongo checkout error (' . $http . '): ' . $raw);
    fail('Could not start GCash payment.', 502);
}

// Any earlier unfinished GCash checkout for this order is replaced by the new one
$pdo->prepare('UPDATE payment SET status = "expired" WHERE order_id = ? AND status = "pending_gateway"')
    ->execute([$orderId]);

$pdo->prepare(
    'INSERT INTO payment (order_id, payment_type, payment_method, amount, provider, provider_ref, status)
     VALUES (?, ?, "gcash", ?, "paymongo", ?, "pending_gateway")'
)->execute([$orderId, $mode, $amount, $res['data']['id']]);

echo json_encode(['checkout_url' => $res['data']['attributes']['checkout_url']]);