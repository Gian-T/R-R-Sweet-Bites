<?php
declare(strict_types=1);
/**
 * Backend for quotation-composer.html
 *   GET  quotation-composer.php?ref=Q-2024-0847  -> quotation data as JSON
 *   POST quotation-composer.php?ref=...          -> action=accept | revision (+ message, csrf)
 */
require __DIR__ . '/../includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load_quote(PDO $pdo, string $ref): ?array
{
    $sql = 'SELECT q.*, c.initials AS client_initials, c.full_name AS client_name
            FROM quotations q JOIN customers c ON c.id = q.customer_id ';
    if ($ref !== '') {
        $st = $pdo->prepare($sql . 'WHERE q.ref_no = ?');
        $st->execute([$ref]);
    } else {
        $st = $pdo->query($sql . 'ORDER BY q.id DESC LIMIT 1');
    }
    return $st->fetch() ?: null;
}

function days_left(string $validUntil): int
{
    return (int) floor((strtotime($validUntil) - strtotime('today')) / 86400);
}

$pdo   = db();
$ref   = trim((string) ($_GET['ref'] ?? ''));
$quote = load_quote($pdo, $ref);
if (!$quote) {
    out(['error' => 'Quotation not found.'], 404);
}

/* ---------- POST: Accept / Request Revision ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $errors = [];
    $fieldErrors = [];
    $okMessage = '';

    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please reload the page and try again.';
    } elseif (!in_array($action, ['accept', 'revision'], true)) {
        $errors[] = 'Unknown action.';
    } else {
        $pdo->beginTransaction();
        // Re-read inside the transaction so a double-click cannot act twice.
        $st = $pdo->prepare('SELECT status, valid_until FROM quotations WHERE id = ?');
        $st->execute([$quote['id']]);
        $cur = $st->fetch();

        if ($cur['status'] !== 'issued') {
            $errors[] = 'This quotation has already been responded to.';
        } elseif (days_left($cur['valid_until']) < 0) {
            $errors[] = 'This quotation has expired. Please submit a new request.';
        } elseif ($action === 'accept') {
            $pdo->prepare("UPDATE quotations SET status = 'accepted', accepted_at = NOW() WHERE id = ?")
                ->execute([$quote['id']]);
            $okMessage = 'Quotation accepted. Thank you!';
        } else {
            $msg = trim((string) ($_POST['message'] ?? ''));
            $len = mb_strlen($msg);
            if ($len < 5) {
                $fieldErrors['message'] = 'Please describe the change you would like (at least 5 characters).';
            } elseif ($len > 1000) {
                $fieldErrors['message'] = 'Revision notes are limited to 1000 characters.';
            } else {
                $pdo->prepare('INSERT INTO quotation_revisions (quotation_id, message) VALUES (?, ?)')
                    ->execute([$quote['id'], $msg]);
                $pdo->prepare("UPDATE quotations SET status = 'revision_requested' WHERE id = ?")
                    ->execute([$quote['id']]);
                $okMessage = 'Revision requested. We will send you an updated quotation once it has been reviewed.';
            }
        }
        ($errors || $fieldErrors) ? $pdo->rollBack() : $pdo->commit();
    }

    if ($errors || $fieldErrors) {
        out(['ok' => false, 'errors' => $errors, 'fieldErrors' => (object) $fieldErrors]);
    }
    out(['ok' => true, 'message' => $okMessage]);
}

/* ---------- GET: data for the page ---------- */
$st = $pdo->prepare('SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order, id');
$st->execute([$quote['id']]);

$items = [];
$grand = 0.0;
foreach ($st->fetchAll() as $it) {
    $line = ($it['qty'] ?? 1) * (float) $it['unit_price'];
    $grand += $line;
    $items[] = [
        'description' => $it['description'],
        'qty'         => $it['qty'] === null ? null : (int) $it['qty'],
        'unit_price'  => peso($it['unit_price']),
        'line_total'  => peso($line),
    ];
}

$left    = days_left($quote['valid_until']);
$expired = $left < 0;
$status  = ($quote['status'] === 'issued' && $expired) ? 'expired' : $quote['status'];

$statusLabels = [
    'issued'             => 'Quotation Issued',
    'accepted'           => 'Accepted',
    'revision_requested' => 'Revision Requested',
    'expired'            => 'Expired',
];

$st = $pdo->prepare('SELECT message, created_at FROM quotation_revisions WHERE quotation_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$quote['id']]);
$rev = $st->fetch() ?: null;

out([
    'csrf'   => csrf_token(),
    'quote'  => [
        'ref_no'          => $quote['ref_no'],
        'reference_id'    => $quote['reference_id'],
        'client_initials' => $quote['client_initials'],
        'admin_initials'  => $quote['admin_initials'],
        'admin_notes'     => $quote['admin_notes'],
        'complexity'      => $quote['complexity'],
        'cake_type'       => $quote['cake_type'],
        'cake_size'       => $quote['cake_size'],
        'issued_at'       => fmt_date($quote['issued_at']),
        'delivery_date'   => fmt_date($quote['delivery_date']),
    ],
    'items'           => $items,
    'total'           => peso($grand),
    'status'          => $status,
    'status_label'    => $statusLabels[$status] ?? $status,
    'can_act'         => $status === 'issued',
    'validity_text'   => $expired ? 'Expired' : ($left === 0 ? 'Expires today' : $left . ($left === 1 ? ' Day' : ' Days') . ' Remaining'),
    'expiry_line'     => ($expired ? 'Quotation expired on ' : 'Quotation expires on ') . fmt_date($quote['valid_until']) . '.',
    'latest_revision' => $rev ? ['message' => $rev['message'], 'sent' => fmt_date($rev['created_at'])] : null,
]);