<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db_connect.php';
require __DIR__ . '/../includes/payments.php';   // STATUS_AFTER_DP_VERIFIED lives here now

$raw    = file_get_contents('php://input');
$header = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

// Header looks like: t=TIMESTAMP,te=TEST_SIG,li=LIVE_SIG
$sig = [];
foreach (explode(',', $header) as $part) {
    [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
    $sig[trim($k)] = trim($v);
}

$secret   = env('PAYMONGO_WEBHOOK_SECRET');
$expected = hash_hmac('sha256', ($sig['t'] ?? '') . '.' . $raw, $secret);
$given    = ($sig['li'] ?? '') !== '' ? $sig['li'] : ($sig['te'] ?? '');

if ($secret === '' || $given === '' || !hash_equals($expected, $given)) {
    error_log('paymongo webhook: bad signature');
    http_response_code(401);
    exit;
}

$event = json_decode($raw, true);
$type  = $event['data']['attributes']['type'] ?? '';
error_log('paymongo webhook: received type=' . $type);

if ($type === 'checkout_session.payment.paid') {
    $session      = $event['data']['attributes']['data'] ?? [];
    $ref          = (string) ($session['id'] ?? '');
    $paidCentavos = (int) ($session['attributes']['payments'][0]['attributes']['amount'] ?? 0);

    try {
        verify_gateway_payment(db(), $ref, $paidCentavos);
    } catch (Throwable $e) {
        error_log('paymongo webhook error: ' . $e->getMessage());
        http_response_code(500);   // let PayMongo retry
        exit;
    }
}

http_response_code(200);