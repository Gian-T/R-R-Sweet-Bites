<?php
declare(strict_types=1);

/* Called by the order page after the customer returns from GCash (?paid=1).
   Asks PayMongo directly whether the checkout was paid, so confirmation does not depend on the webhook. */

require __DIR__ . '/../includes/db_connect.php';
require __DIR__ . '/../includes/payments.php';

header('Content-Type: application/json; charset=utf-8');

function reply(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(['error' => 'POST only.'], 405);

$pdo        = db();
$customerId = require_customer();
if (!csrf_ok()) reply(['error' => 'Session expired.'], 419);

$orderId = filter_input(INPUT_POST, 'order', FILTER_VALIDATE_INT);
if (!$orderId) reply(['error' => 'Order not found.'], 404);

$st = $pdo->prepare('SELECT order_id FROM `order` WHERE order_id = ? AND customer_id = ?');
$st->execute([$orderId, $customerId]);
if (!$st->fetch()) reply(['error' => 'Order not found.'], 404);

$st = $pdo->prepare(
    "SELECT provider_ref FROM payment
     WHERE order_id = ? AND provider = 'paymongo' AND status IN ('pending_gateway','expired')
     ORDER BY payment_id DESC LIMIT 3"
);
$st->execute([$orderId]);

$confirmed = false;
foreach ($st->fetchAll() as $row) {
    $paid = paymongo_paid_amount((string) $row['provider_ref']);
    if ($paid !== null && verify_gateway_payment($pdo, (string) $row['provider_ref'], $paid)) {
        $confirmed = true;
    }
}

reply(['confirmed' => $confirmed]);