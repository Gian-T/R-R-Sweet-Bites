<?php
declare(strict_types=1);

/* Shared payment helpers. Require this from customer-orders.php,
   gcash-checkout.php and paymongo-webhook.php (after db_connect.php). */

if (!function_exists('env')) {
    function env(string $key): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($v !== false && $v !== null && $v !== '') return (string) $v;

        static $file = null;
        if ($file === null) {
            $path = __DIR__ . '/../.env';   // adjust if your .env is elsewhere
            $file = is_file($path) ? (parse_ini_file($path, false, INI_SCANNER_RAW) ?: []) : [];
        }
        return trim((string) ($file[$key] ?? ''), "\"'");
    }
}

/** Money figures for an order, recomputed from the payment table.
 *  Note: pending_gateway (unfinished GCash checkout) is deliberately NOT counted as pending,
 *  so an abandoned checkout never blocks the customer from trying again. */
function order_figures(PDO $pdo, array $order): array
{
    $st = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'verified' THEN amount END), 0) AS verified,
            COALESCE(SUM(status = 'verified'), 0) AS verified_count,
            COALESCE(SUM(status = 'pending_verification'), 0) AS pending_count
         FROM payment WHERE order_id = ?"
    );
    $st->execute([$order['order_id']]);
    $p = $st->fetch();

    $total    = (float) ($order['quoted_price'] ?? 0.00);
    $dpReq    = round($total * 0.20, 2);   // 20% downpayment
    $verified = (float) $p['verified'];

    return [
        'total'         => $total,
        'dp_required'   => $dpReq,
        'verified'      => $verified,
        'remaining'     => max($total - $verified, 0),
        'paid_pct'      => $total > 0 ? min(100, (int) round($verified / $total * 100)) : 0,
        'verified_cnt'  => (int) $p['verified_count'],
        'has_pending'   => (int) $p['pending_count'] > 0,
        'dp_paid'       => $verified + 0.001 >= $dpReq,
        'amount_due'    => max($dpReq - $verified, 0),
        'balance_after' => max($total - max($verified, $dpReq), 0),
    ];
}

/** One place that decides what the customer can do. Pass $order to include review/quoted checks. */
function pay_mode(array $fig, ?array $order = null): string
{
    if ($order !== null) {
        if ($order['status'] === 'pending_review') return 'review';
        if ($order['status'] === 'quoted')         return 'quoted';
    }
    if ($fig['total'] <= 0)      return 'quoted';   // no accepted quotation yet
    if ($fig['has_pending'])     return 'pending';
    if (!$fig['dp_paid'])        return 'downpayment';
    if ($fig['remaining'] > 0)   return 'balance';
    return 'paid';
}

/* ---------- Gateway (PayMongo) confirmation helpers ---------- */

/* Legacy constant kept for backwards-compatibility with other files that may reference it. */
const STATUS_AFTER_DP_VERIFIED = 'in_production';

/** Ask PayMongo whether a checkout session was paid. Returns the paid amount in centavos, or null if not paid. */
function paymongo_paid_amount(string $sessionId): ?int
{
    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($sessionId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => env('PAYMONGO_SECRET_KEY') . ':',
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res = json_decode((string) $raw, true);
    if ($http !== 200 || !is_array($res)) {
        error_log('paymongo lookup failed (' . $http . '): ' . $raw);
        return null;
    }
    foreach ($res['data']['attributes']['payments'] ?? [] as $pay) {
        if (($pay['attributes']['status'] ?? '') === 'paid') {
            return (int) ($pay['attributes']['amount'] ?? 0);
        }
    }
    return null;
}

/**
 * Records a gateway payment as PAID but leaves it for MANUAL admin verification.
 *
 * - Moves the payment row from pending_gateway/expired → pending_verification.
 * - Moves the order to downpayment_pending_verification (same as a manual upload).
 * - Notifies every admin that a payment is waiting for review.
 *
 * Safe to call twice (webhook + page check): already-queued rows return true without re-doing work.
 */
function verify_gateway_payment(PDO $pdo, string $ref, int $paidCentavos): bool
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT p.*, o.customer_id, o.status AS order_status
               FROM payment p
               JOIN `order` o ON o.order_id = p.order_id
              WHERE p.provider_ref = ? FOR UPDATE'
        );
        $st->execute([$ref]);
        $pay = $st->fetch();

        // Row not found, or the amount PayMongo reports doesn't match what we expected.
        if (!$pay || (int) round(((float) $pay['amount']) * 100) !== $paidCentavos) {
            error_log('gateway verify: no matching row (or amount mismatch) for ' . $ref);
            $pdo->commit();
            return false;
        }

        // Already handled (webhook retry, or page already polled): say yes, do nothing.
        if (in_array($pay['status'], ['pending_verification', 'verified'], true)) {
            $pdo->commit();
            return true;
        }

        if (!in_array($pay['status'], ['pending_gateway', 'expired'], true)) {
            error_log('gateway verify: unexpected status ' . $pay['status'] . ' for ' . $ref);
            $pdo->commit();
            return false;
        }

        // Park for MANUAL review — the admin clicks Approve on the payment page.
        $pdo->prepare('UPDATE payment SET status = "pending_verification" WHERE payment_id = ?')
            ->execute([$pay['payment_id']]);

        // Same order transition a manual downpayment upload triggers.
        if ($pay['payment_type'] === 'downpayment' && $pay['order_status'] === 'confirmed') {
            $pdo->prepare('UPDATE `order` SET status = "downpayment_pending_verification" WHERE order_id = ?')
                ->execute([$pay['order_id']]);
        }

        // Notify every admin that a GCash payment is waiting for them.
        // Uses valid recipient_ids (the notification table requires NOT NULL).
        $orderNo = fmt_id($pay['order_id']);
        $amount  = peso($pay['amount']);
        $msg = "GCash payment of {$amount} for order {$orderNo} is awaiting your verification.";
        $notify = $pdo->prepare(
            "INSERT INTO notification (order_id, recipient_type, recipient_id, message)
             VALUES (?, 'admin', ?, ?)"
        );
        foreach ($pdo->query('SELECT admin_id FROM admin')->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            $notify->execute([$pay['order_id'], (int) $aid, mb_substr($msg, 0, 500)]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}