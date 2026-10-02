<?php
declare(strict_types=1);

require __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
});

const MAX_PROOF_BYTES = 5 * 1024 * 1024; // 5 MB
const PROOF_TYPES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/pdf' => 'pdf',
];
const METHOD_LABELS = [
    'gcash'         => 'GCash', 
    'bank_transfer' => 'Bank Transfer',
    'cash'          => 'Cash'
];

function out(array $data, int$code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$pdo = db();

/* ---------- Load order (?order=1, falls back to the first order) ---------- */
$orderId = filter_input(INPUT_GET, 'order', FILTER_VALIDATE_INT);

// Joins with customer and accepted quotation to compute pricing figures
$sql = 'SELECT o.*, c.full_name, q.quoted_price 
        FROM `order` o 
        JOIN customer c ON c.customer_id = o.customer_id 
        LEFT JOIN quotation q ON q.order_id = o.order_id AND q.status = "accepted" ';

if ($orderId) {$st = $pdo->prepare($sql . 'WHERE o.order_id = ?');
    $st->execute([$orderId]);
} else {
    $st = $pdo->query($sql . 'ORDER BY o.order_id ASC LIMIT 1');
}

$order =$st->fetch();
if (!$order) {
    out(['error' => 'Order not found.'], 404);
}

/** Money figures for an order, recomputed from payment table */
function order_figures(PDO $pdo, array$order): array
{
    $st =$pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'verified' THEN amount END), 0) AS verified,
            SUM(status = 'verified') AS verified_count,
            SUM(status = 'pending_verification') AS pending_count
         FROM payment WHERE order_id = ?"
    );
    $st->execute([$order['order_id']]);
    $p =$st->fetch();

    $total = (float) ($order['quoted_price'] ?? 0.00);
    $dpReq = round($total * 0.50, 2); // Defaulting downpayment required to 50%
    $verified = (float)$p['verified'];

    return [
        'total'         => $total,
        'dp_required'   => $dpReq,
        'verified'      => $verified,
        'remaining'     => max($total -$verified, 0),
        'paid_pct'      => $total > 0 ? min(100, (int) round($verified / $total * 100)) : 0,         'verified_cnt'  => (int)$p['verified_count'],
        'has_pending'   => (int) $p['pending_count'] > 0,
        'dp_paid'       => $verified + 0.001 >=$dpReq,
        'amount_due'    => max($dpReq - $verified, 0),         'balance_after' => max($total - max($verified,$dpReq), 0),
    ];
}

/* ---------- POST: downpayment submission ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {$errors  = [];
    $general = [];$method  = (string) ($_POST['method'] ?? '');$saved   = null;
    $mime    = '';

    if (!csrf_ok()) {
        $general[] = 'Your session expired. Please reload the page and try again.';
    } else {
        $fig = order_figures($pdo,$order);

        if ($fig['dp_paid']) {$general[] = 'The downpayment for this order is already settled.';
        } elseif ($fig['has_pending']) {$general[] = 'You already have a payment waiting for verification.';
        }

        // Map 'bank' to schema enum 'bank_transfer'
        if ($method === 'bank') {$method = 'bank_transfer';
        }

        if (!in_array($method, ['gcash', 'bank_transfer'], true)) {$errors['method'] = 'Please choose a payment method.';
        }

        $amount = round($fig['amount_due'], 2);

        // Proof of payment
        $f =$_FILES['proof'] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {$errors['proof'] = 'Please upload your proof of payment.';
        } elseif ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > MAX_PROOF_BYTES) {$errors['proof'] = 'Proof of payment must be 5 MB or smaller.';
        } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {$errors['proof'] = 'The upload failed. Please try again.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if (!isset(PROOF_TYPES[$mime])) {$errors['proof'] = 'Proof of payment must be a JPG, PNG or PDF file.';
            }
        }

        if (!$errors && !$general) {
            $fileName = bin2hex(random_bytes(16)) . '.' . PROOF_TYPES[$mime];
            $saved    = __DIR__ . '/uploads/proofs/' .$fileName;

            if (!move_uploaded_file($f['tmp_name'], $saved)) {$errors['proof'] = 'Could not save the uploaded file. Check that uploads/proofs is writable.';
            } else {
                try {
                    $pdo->prepare(
                        'INSERT INTO payment (order_id, payment_type, payment_method, amount, proof_image_url, status)
                         VALUES (?, "downpayment", ?, ?, ?, "pending_verification")'
                    )->execute([$order['order_id'],$method, $amount,$fileName]);

                    // Update order status
                    $pdo->prepare('UPDATE `order` SET status = "downpayment_pending_verification" WHERE order_id = ?')
                        ->execute([$order['order_id']]);

                    out(['ok' => true, 'message' => 'Downpayment submitted! We will verify it shortly.']);
                } catch (PDOException $ex) {
                    @unlink($saved);$general[] = 'Could not save your payment. Please try again.';
                }
            }
        }
    }

    out(['ok' => false, 'general' => $general, 'fieldErrors' => (object)$errors]);
}

/* ---------- GET: data for the page ---------- */
$fig = order_figures($pdo,$order);

$st =$pdo->prepare('SELECT * FROM payment WHERE order_id = ? ORDER BY created_at DESC, payment_id DESC');
$st->execute([$order['order_id']]);$payments = [];

foreach ($st->fetchAll() as $p) {$payments[] = [
        'date'         => fmt_date($p['created_at']),
        'method'       => METHOD_LABELS[$p['payment_method']] ?? $p['payment_method'],
        'amount'       => peso($p['amount']),
        'status'       => $p['status'],
        'status_label' => ucfirst(str_replace('_', ' ', $p['status'])),
    ];
}

// Generate client initials from full_name
$nameParts = explode(' ', trim($order['full_name']));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr(end($nameParts), 0, 1) : ''));

out([
    'csrf'            => csrf_token(),
    'max_proof_bytes' => MAX_PROOF_BYTES,
    'order' => [
        'order_no'        => 'ORD-' . str_pad((string) $order['order_id'], 5, '0', STR_PAD_LEFT),
        'title'           => ucfirst($order['cake_type']) . ' - ' .$order['flavor'],
        'occasion'        => $order['design_description'],
        'client_initials' => $initials,
        'dp_percent'      => 50,
    ],
    'figures' => [
        'total'         => peso($fig['total']),
        'dp_required'   => peso($fig['dp_required']),
        'verified'      => peso($fig['verified']),
        'remaining'     => peso($fig['remaining']),
        'balance_after' => peso($fig['balance_after']),
        'amount_due'    => peso($fig['amount_due']),
        'amount_val'    => number_format($fig['amount_due'], 2, '.', ''),         'paid_pct'      =>$fig['paid_pct'],
        'verified_cnt'  => $fig['verified_cnt'],
    ],
    'dp_paid'    => $fig['dp_paid'],
    'dp_status'  => $fig['dp_paid'] ? 'Paid' : ($fig['has_pending'] ? 'Pending Verification' : 'Not Paid'),
    'can_pay'    => !$fig['dp_paid'] && !$fig['has_pending'],
    'payments'   => $payments,
]);