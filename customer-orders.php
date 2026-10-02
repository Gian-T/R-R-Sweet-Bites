<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$pdo = db();

const MAX_PROOF_BYTES = 5 * 1024 * 1024;           // 5 MB
const PROOF_TYPES = [                              // real MIME type => saved extension
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/pdf' => 'pdf',
];

/* ---------- Load order (?order=RR-2041, falls back to the first order) ---------- */
$orderNo = trim((string) ($_GET['order'] ?? ''));
$sql = 'SELECT o.*, c.initials AS client_initials FROM orders o JOIN customers c ON c.id = o.customer_id ';
if ($orderNo !== '') {
    $st = $pdo->prepare($sql . 'WHERE o.order_no = ?');
    $st->execute([$orderNo]);
} else {
    $st = $pdo->query($sql . 'ORDER BY o.id ASC LIMIT 1');
}
$order = $st->fetch();
if (!$order) {
    not_found('Order');
}
$self = 'customer-orders.php?order=' . urlencode($order['order_no']);

/** Money figures for an order, always recomputed from the payments table. */
function order_figures(PDO $pdo, array $order): array
{
    $st = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'verified' THEN amount END), 0) AS verified,
            SUM(status = 'verified') AS verified_count,
            SUM(status = 'pending')  AS pending_count
         FROM payments WHERE order_id = ?"
    );
    $st->execute([$order['id']]);
    $p = $st->fetch();

    $total    = (float) $order['total_amount'];
    $dpReq    = round($total * $order['downpayment_percent'] / 100, 2);
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

/* ---------- Handle downpayment submission ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors  = [];   // field-specific messages: method | amount | reference | proof
    $general = [];   // messages that don't belong to a single field
    $method = (string) ($_POST['method'] ?? '');
    $refNo  = trim((string) ($_POST['reference'] ?? ''));
    $saved  = null;   // path of an uploaded file, so we can delete it if the insert fails

    if (!csrf_ok()) {
        $general[] = 'Your session expired. Please try again.';
    } else {
        $fig = order_figures($pdo, $order);

        if ($fig['dp_paid']) {
            $general[] = 'The downpayment for this order is already settled.';
        } elseif ($fig['has_pending']) {
            $general[] = 'You already have a payment waiting for verification.';
        }

        if (!in_array($method, ['gcash', 'bank'], true)) {
            $errors['method'] = 'Please choose a payment method.';
        }

        // The amount is fixed: customers pay exactly the required downpayment (GCash / bank only, no cash).
        // Whatever the browser sends is ignored.
        $amount = round($fig['amount_due'], 2);

        if (!preg_match('/^\d{4,40}$/', $refNo)) {
            $errors['reference'] = 'Reference / transaction number must be 4-40 digits (numbers only).';
        }

        // Proof of payment (required for GCash and bank transfer)
        $f = $_FILES['proof'] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['proof'] = 'Please upload your proof of payment.';
        } elseif ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > MAX_PROOF_BYTES) {
            $errors['proof'] = 'Proof of payment must be 5 MB or smaller.';
        } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errors['proof'] = 'The upload failed. Please try again.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if (!isset(PROOF_TYPES[$mime])) {
                $errors['proof'] = 'Proof of payment must be a JPG, PNG or PDF file.';
            }
        }

        if (!$errors && !$general) {
            $fileName = bin2hex(random_bytes(16)) . '.' . PROOF_TYPES[$mime];
            $saved    = __DIR__ . '/uploads/proofs/' . $fileName;
            if (!move_uploaded_file($f['tmp_name'], $saved)) {
                $errors['proof'] = 'Could not save the uploaded file. Check that uploads/proofs is writable.';
            } else {
                try {
                    $pdo->prepare(
                        'INSERT INTO payments (order_id, method, amount, reference_no, proof_file)
                         VALUES (?, ?, ?, ?, ?)'
                    )->execute([$order['id'], $method, $amount, $refNo, $fileName]);
                    flash_ok('Downpayment submitted! We will verify it shortly.');
                    redirect($self);
                } catch (PDOException $ex) {
                    @unlink($saved);
                    if ($ex->getCode() === '23000') {
                        $errors['reference'] = 'That reference number has already been submitted.';
                    } else {
                        $general[] = 'Could not save your payment. Please try again.';
                    }
                }
            }
        }
    }

    flash_errors($general);
    $_SESSION['field_errors'] = $errors;
    $_SESSION['old_payment'] = ['method' => $method, 'reference' => $refNo];
    redirect($self);
}

/* ---------- View data ---------- */
$fig = order_figures($pdo, $order);

$st = $pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC, id DESC');
$st->execute([$order['id']]);
$payments = $st->fetchAll();

$dpStatus = $fig['dp_paid'] ? 'Paid' : ($fig['has_pending'] ? 'Pending Verification' : 'Not Paid');
$canPay   = !$fig['dp_paid'] && !$fig['has_pending'];

$old = $_SESSION['old_payment'] ?? [];
unset($_SESSION['old_payment']);
$fieldErrors = $_SESSION['field_errors'] ?? [];
unset($_SESSION['field_errors']);
/** Inline error line shown under a field (empty + hidden until there is a message). */
function field_err(array $fe, string $k): string
{
    $m = $fe[$k] ?? '';
    return '<p class="ferr" id="err-' . $k . '" role="alert"' . ($m === '' ? ' hidden' : '') . '>' . e($m) . '</p>';
}
function bad(array $fe, string $k): string
{
    return isset($fe[$k]) ? ' bad' : '';
}
$selMethod = in_array($old['method'] ?? '', ['gcash', 'bank'], true) ? $old['method'] : 'gcash';
$amountVal = number_format($fig['amount_due'], 2, '.', '');   // fixed: always the exact downpayment due
$refVal    = $old['reference'] ?? '';
$methodLabels = ['gcash' => 'GCash', 'bank' => 'Bank Transfer'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>R&R Sweet Bites – Order #RR-2041</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&family=Platypi:wght@400;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#FDF6F1;            /* page background */
  --bg-alt:#F8ECE4;        /* secondary sections, alt cards */
  --pink-light:#F6D8D6;    /* hero circle, icon bg, tags, CTA banner */
  --pink:#D97D8A;          /* buttons, links, headings, icons */
  --pink-text:#D97D8A;
  --brown:#4A2F2B;         /* headings, nav text, body copy */
  --muted:#8A6A62;         /* paragraph text, captions */
  --border:#F0D3CB;        /* input borders, hairlines */
  --footer:#F0D3CB;
  --line:#F0D3CB;          /* footer / hairlines (shared with order-confirmation) */
  --ink:#4A2F2B;
  --white:#FFFFFF;         /* cards, form fields */
  --status-success:#829B7A;
  --status-error:#C65F62;
  --status-pending:#D6A85F;
  /* design system tokens (header) */
  --color-primary:var(--pink);
  --brand-icon-bg:var(--pink-light);
  --color-text-dark:var(--brown);
  --font-brand:'Platypi',Georgia,serif;
  --font-nav:'Jost',system-ui,sans-serif;
  --text-sm:0.875rem;
  --text-base:1rem;
  --text-lg:1.35rem;
  --space-1:0.25rem;
  --space-2:0.5rem;
  --space-3:1rem;
  --space-5:2rem;
  --radius-full:50%;
  --shadow-sm:0 2px 10px rgba(0,0,0,0.03);
  box-sizing:border-box;
  padding-top:env(safe-area-inset-top,0px);
  padding-bottom:env(safe-area-inset-bottom,0px);
}
html{scroll-padding-top:env(safe-area-inset-top,0px);}
@media (prefers-color-scheme: dark){
  :root:not([data-theme="light"]){
    --bg:#FDF6F1; --white:#fff;
  }
}
:root[data-theme="dark"]{--bg:#FDF6F1;--white:#fff;}
*,*::before,*::after{box-sizing:inherit;}
html,body{margin:0;}
body{
  background:var(--bg);
  color:var(--brown);
  font-family:'Jost',system-ui,sans-serif;
  font-size:14px;
  line-height:1.3;
  -webkit-font-smoothing:antialiased;
  min-height:100vh;display:flex;flex-direction:column;   /* keeps the footer at the bottom */
}
a{color:inherit;text-decoration:none;}
button{font-family:inherit;cursor:pointer;}

/* ---------- Header ----------
   Phone layout: sizes & positions measured from the Figma frame
   (brand left, nav centred, bell + avatar right).
   >=900px: design-system sizes (1.35rem brand, 1rem nav, 2rem gaps, 5% margins). */
.topbar{
  position:sticky;top:env(safe-area-inset-top,0px);z-index:10;
  background:var(--bg);box-shadow:var(--shadow-sm);
  display:grid;grid-template-columns:auto 1fr auto;align-items:center;
  column-gap:8px;
  padding:7px 17px 5px 13px;
}
.brand{display:flex;align-items:center;gap:7px;min-width:0;}
.logo{
  width:24px;height:24px;border-radius:var(--radius-full);
  background:var(--brand-icon-bg);
  display:grid;place-items:center;flex:none;overflow:hidden;
}
.logo svg,.logo img{width:16px;height:16px;object-fit:contain;}
.brand-name{
  font-family:var(--font-brand);font-weight:600;font-size:10px;line-height:1;
  color:var(--color-primary);white-space:nowrap;
}
.nav{
  justify-self:center;display:flex;align-items:center;gap:9.5px;
  font-family:var(--font-nav);font-size:9px;font-weight:400;line-height:1;
}
.nav a{position:relative;display:block;color:var(--color-text-dark);white-space:nowrap;transition:color .15s;}
.nav a:hover{color:var(--color-primary);}
/* active link: text sits ~4px higher with a 1px pink underline below it, as in Figma */
.nav a.active{transform:translateY(-4px);}
.nav a.active::after{
  content:"";position:absolute;left:0;right:0;bottom:-2.5px;height:1px;background:var(--color-primary);
}
.actions{justify-self:end;display:flex;align-items:center;gap:8px;}
.bell{position:relative;width:16px;height:16px;color:var(--color-text-dark);display:grid;place-items:center;}
.bell svg,.bell img{width:100%;height:100%;object-fit:contain;}
.bell i{position:absolute;top:0;right:0;width:5px;height:5px;border-radius:var(--radius-full);background:var(--status-error);}
.avatar{
  width:24px;height:24px;border-radius:var(--radius-full);
  background:var(--brand-icon-bg);color:var(--color-text-dark);
  display:grid;place-items:center;
  font-family:var(--font-nav);font-weight:600;font-size:8px;
}
/* keep nav truly centred once there is room for three equal columns */
@media (min-width:440px){
  .topbar{grid-template-columns:1fr auto 1fr;}
}
@media (min-width:900px){
  .topbar{padding:var(--space-3) 5%;column-gap:var(--space-5);}
  .brand{gap:var(--space-2);}
  .logo{width:2.5rem;height:2.5rem;}
  .logo svg,.logo img{width:26px;height:26px;}
  .brand-name{font-size:var(--text-lg);}
  .nav{gap:var(--space-5);font-size:var(--text-base);}
  .nav a.active{transform:none;}
  .nav a.active::after{bottom:-0.25rem;height:2px;}
  .actions{gap:var(--space-3);}
  .bell{width:1.25rem;height:1.25rem;}
  .avatar{width:2.25rem;height:2.25rem;font-size:var(--text-sm);}
}

/* ---------- Page ---------- */
.page{flex:1;width:100%;max-width:960px;margin:0 auto;padding:0 8px 32px;}
.crumbs{padding:0 20px;}
.back{
  display:inline-flex;align-items:center;gap:5px;
  border:1px solid var(--pink);color:var(--pink);background:transparent;
  border-radius:4px;font-size:8.5px;font-weight:500;padding:3px 10px;margin-top:8px;
}
.trail{font-size:8.5px;margin:6px 0 0;color:var(--muted);}
.trail span{text-decoration:underline;}
h1.order{
  font-family:'Playfair Display',serif;font-weight:500;font-size:20px;margin:10px 0 4px;color:var(--brown);
}
.sub{font-size:8.5px;margin:0 0 14px;color:var(--muted);}

.tabs{display:flex;gap:11px;margin:0 20px 14px;font-size:10px;font-weight:500;}
.tabs button{
  background:none;border:0;padding:2px 0 3px;color:var(--brown);font-size:10px;font-weight:500;
}
.tabs button.on{color:var(--pink);border-bottom:1.5px solid var(--pink);}

/* ---------- Balance card ---------- */
.balance{
  margin:0 19px;background:var(--pink);color:#fff;border-radius:6px;
  padding:14px 14px 14px;
}
.balance .lbl{font-size:8.5px;letter-spacing:.2px;}
.balance .amt{
  font-family:'Playfair Display',serif;font-weight:600;font-size:23px;margin:4px 0 9px;
}
.bar{height:7px;background:rgba(255,255,255,.6);border-radius:4px;overflow:hidden;}
.bar b{display:block;height:100%;width:0;background:#fff;}
.bar-meta{display:flex;justify-content:space-between;font-size:7.5px;margin:3px 0 7px;}
.rule{height:1px;background:rgba(255,255,255,.75);margin:0 0 9px;}
.stats{display:grid;grid-template-columns:1fr 1fr;row-gap:11px;column-gap:10px;}
.stats dt{font-size:8px;font-weight:600;}
.stats dd{margin:2px 0 0;font-size:8px;font-weight:500;}
.stats div{min-width:0;}

/* ---------- Submit panel ---------- */
.panel{
  margin:12px 0 0;border:1px solid var(--border);border-radius:6px;
  padding:12px 12px 14px;
}
.panel h2{
  font-family:'Playfair Display',serif;font-weight:600;font-size:16px;margin:0 0 3px 2px;color:var(--brown);
}
.panel .hint{font-size:7.5px;margin:0 0 12px 2px;color:var(--muted);}

.summary{
  border:1px solid var(--border);border-radius:6px;padding:14px 14px 10px;margin:0 0 16px;position:relative;
}
.row{display:flex;justify-content:space-between;align-items:center;font-size:9.5px;font-weight:600;margin-bottom:14px;}
.row.price{font-weight:700;}
.divider{width:42px;height:1px;background:var(--border);margin:-10px auto 12px;}
.divider-full{height:1px;background:var(--border);margin:-5px 0 8px;}
.topay{
  display:flex;justify-content:space-between;align-items:baseline;
  font-family:'Playfair Display',serif;font-weight:500;color:var(--pink-text);
  margin-top:4px;
}
.topay .l{font-size:13px;}
.topay .r{font-size:13px;font-weight:600;}

.field-label{font-size:8.5px;font-weight:600;margin:0 0 5px 2px;display:block;}
.methods{display:grid;grid-template-columns:1fr 1fr;gap:34px;margin:0 6px 16px 22px;}
.method{
  background:#fff;border:1px solid var(--border);border-radius:5px;
  height:30px;display:flex;flex-direction:column;align-items:center;justify-content:center;
  color:var(--brown);padding:0 6px;
}
.method strong{font-size:9px;font-weight:600;letter-spacing:.1px;}
.method small{font-size:5.5px;margin-top:1px;font-weight:500;color:var(--muted);}
.method.sel{border:1.5px solid var(--pink);}
.method:focus-visible,.drop:focus-visible,.submit:focus-visible,.inp:focus-visible,.nav a:focus-visible,.tabs button:focus-visible,.back:focus-visible{
  outline:2px solid var(--brown);outline-offset:2px;
}

.cols{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 14px;}
.cols .field-label{margin-left:2px;}
.inp{
  width:100%;height:16px;border:1px solid var(--border);background:#fff;border-radius:3px;
  padding:0 6px;font:inherit;font-size:9px;color:var(--brown);
}
.cols .field-label{margin-left:2px}
.inp-wrap{margin-top:0;}
.colA{padding-left:0}

.drop{
  background:#fff;border:1px solid var(--border);border-radius:5px;
  min-height:73px;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:6px;text-align:center;padding:10px;cursor:pointer;margin:6px 0 11px;
  transition:border-color .15s, background .15s;
}
.drop.over{border-color:var(--pink);background:var(--bg-alt);}
.drop .click{font-size:9px;display:flex;align-items:center;gap:4px;}
.drop .click b{font-weight:600;}
.drop .types{font-size:6.5px;font-weight:600;color:var(--muted);letter-spacing:.1px;}
.drop .none{font-size:8.5px;color:var(--pink-text);}
.drop input{display:none;}
.drop .preview{display:block;max-width:100%;max-height:180px;border-radius:5px;border:1px solid var(--border);object-fit:contain;background:var(--bg);}
.drop .pdfchip{display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:600;color:var(--pink-text);background:var(--bg-alt);border:1px solid var(--border);border-radius:5px;padding:6px 10px;}
.drop .rm{background:none;border:1px solid var(--pink);color:var(--pink);border-radius:4px;font-size:8.5px;font-weight:500;padding:2px 10px;}
.drop .none.err{color:var(--status-error);}
.inp.locked{background:var(--bg-alt);color:var(--brown);font-weight:600;cursor:not-allowed;}
.lock-note{display:block;margin:4px 2px 0;font-size:8px;color:var(--muted);}
@media (min-width:640px){.lock-note{font-size:10px}}
/* inline field errors */
.ferr{display:flex;align-items:flex-start;gap:5px;margin:4px 2px 0;font-size:8.5px;font-weight:500;line-height:1.35;color:var(--status-error);}
.ferr::before{content:"!";flex:none;width:11px;height:11px;margin-top:1px;border-radius:50%;background:var(--status-error);color:#fff;font-size:8px;font-weight:700;display:grid;place-items:center;}
.ferr[hidden]{display:none;}
.inp.bad{border-color:var(--status-error);background:#FFF8F8;}
.drop.bad{border-color:var(--status-error);background:#FFF8F8;}
.methods.bad .method:not(.sel){border-color:var(--status-error);}
.methods + .ferr{margin:-8px 2px 12px 22px;}
@media (min-width:640px){.ferr{font-size:11px}.ferr::before{width:13px;height:13px;font-size:9px}.methods + .ferr{margin:-14px 2px 16px 0}}
.drop .preview[hidden],.drop .pdfchip[hidden],.drop .rm[hidden]{display:none;}
.drop .preview{cursor:zoom-in;}
.drop .hint-tap{font-size:8px;color:var(--muted);}
.drop .hint-tap[hidden]{display:none;}
.lightbox{position:fixed;inset:0;z-index:100;background:rgba(74,47,43,.88);display:flex;align-items:center;justify-content:center;padding:16px;cursor:zoom-out;}
.lightbox[hidden]{display:none;}
.lightbox img{width:100%;height:100%;object-fit:contain;background:transparent;}
.lightbox .x{position:absolute;top:calc(12px + env(safe-area-inset-top,0px));right:14px;width:36px;height:36px;border-radius:50%;border:0;background:#fff;color:var(--brown);font-size:22px;line-height:1;cursor:pointer;}

.submit{
  display:block;width:100%;height:28px;border:0;border-radius:15px;
  background:var(--pink);color:#fff;
  font-family:'Playfair Display',serif;font-weight:600;font-size:13px;
}
.submit:hover{filter:brightness(.97);}

/* Footer (same as order-confirmation.html) */
footer{background:var(--line);text-align:center;padding:18px 16px 14px;line-height:normal}
.flinks{display:flex;justify-content:center;align-items:center;gap:10px;font-size:12px;font-weight:500;color:var(--ink)}
.flinks a{display:flex;align-items:center;gap:6px}
.flinks svg{width:13px;height:13px}
.flinks i{width:1px;height:14px;background:var(--ink)}
.copy{margin:3px 0 0;font-size:12px;color:var(--ink)}
.bar-top{height:4px;background:var(--brown);}

/* ---------- Larger screens ---------- */
@media (min-width:640px){
  body{font-size:16px;}
  .page{padding:0 24px 32px;}
  .crumbs,.tabs{padding-left:0;padding-right:0;margin-left:0;margin-right:0;}
  .back{font-size:11px;padding:4px 12px;}
  .trail{font-size:11px;}
  h1.order{font-size:26px;}
  .sub{font-size:11px;}
  .tabs,.tabs button{font-size:13px;}
  .balance{margin:0;padding:22px;}
  .balance .lbl{font-size:11px;}
  .balance .amt{font-size:32px;}
  .bar{height:9px;}
  .bar-meta{font-size:10px;}
  .stats dt,.stats dd{font-size:11px;}
  .panel{padding:22px 24px;margin-top:18px;}
  .panel h2{font-size:21px;}
  .panel .hint{font-size:10px;}
  .row{font-size:13px;}
  .topay .l,.topay .r{font-size:18px;}
  .field-label{font-size:11px;}
  .methods{margin:0 0 22px;gap:24px;}
  .method{height:44px;}
  .method strong{font-size:13px;}
  .method small{font-size:8px;}
  .inp{height:26px;font-size:12px;}
  .drop{min-height:110px;}
  .drop .click{font-size:12px;}
  .drop .types{font-size:9px;}
  .drop .none{font-size:11px;}
  .drop .preview{max-height:260px;}
  .drop .pdfchip,.drop .rm{font-size:11px;}
  .submit{height:40px;font-size:17px;border-radius:22px;}
}
<?= FLASH_CSS ?>
.flash{margin:0 20px 12px}
.hist{margin:12px 0 0;border:1px solid var(--border);border-radius:6px;padding:12px;overflow-x:auto}
.hist h2{font-family:'Playfair Display',serif;font-weight:600;font-size:16px;margin:0 0 8px 2px;color:var(--brown)}
.hist table{width:100%;border-collapse:collapse;font-size:9px;color:var(--brown)}
.hist th{text-align:left;font-weight:600;color:var(--muted);padding:4px 6px;border-bottom:1px solid var(--border);white-space:nowrap}
.hist td{padding:6px;border-bottom:1px solid var(--border);white-space:nowrap}
.hist .badge{display:inline-block;padding:1px 7px;border-radius:8px;font-size:8px;font-weight:600}
.hist .badge.verified{background:#E9EFE5;color:#829B7A}
.hist .badge.pending{background:rgba(214,168,95,.25);color:#B98733}
.hist .badge.rejected{background:#FBE9E9;color:#C65F62}
.hist .empty{font-size:9px;color:var(--muted);margin:2px}
.notice{font-size:9px;color:var(--muted);line-height:1.5;margin:0 2px}
@media (min-width:900px){.hist table,.hist .empty,.notice{font-size:12px}.hist .badge{font-size:10px}.hist{padding:22px 24px;margin-top:18px}}
</style>
</head>
<body>

<header class="topbar">
  <a class="brand" href="#" aria-label="R&R Sweet Bites home">
    <span class="logo" aria-hidden="true">
      <img src="" alt="">
    </span>
    <span class="brand-name">R&amp;R Sweet Bites</span>
  </a>
  <nav class="nav" aria-label="Main">
    <a href="#">Home</a>
    <a href="#">Gallery</a>
    <a href="#">Submit Request</a>
    <a href="#" class="active" aria-current="page">My Order</a>
    <a href="#">Messages</a>
  </nav>
  <div class="actions">
    <span class="bell" role="img" aria-label="Notifications">
      <img src="" alt="">
      <i></i>
    </span>
    <span class="avatar"><?= e($order['client_initials']) ?></span>
  </div>
</header>

<main class="page">
  <div class="crumbs">
    <button class="back" type="button">‹&nbsp; Back to my Orders</button>
    <p class="trail">My Orders / <span>Order Detail</span></p>
    <h1 class="order">Order #<?= e($order['order_no']) ?></h1>
    <p class="sub"><?= e($order['title']) ?> · <?= e($order['occasion']) ?></p>
  </div>

  <?php render_flash(); ?>
  

  <div class="tabs" role="tablist">
    <button type="button" class="on" role="tab" aria-selected="true" data-pane="pane-pay">Payment &amp; Balance</button>
    <button type="button" role="tab" aria-selected="false" data-pane="pane-history">Payment History</button>
  </div>

  <div id="pane-pay">
  <section class="balance" aria-label="Remaining balance">
    <div class="lbl">REMAINING BALANCE</div>
    <div class="amt"><?= e(peso($fig['remaining'])) ?></div>
    <div class="bar" role="progressbar" aria-valuenow="<?= (int) $fig['paid_pct'] ?>" aria-valuemin="0" aria-valuemax="100"><b style="width:<?= (int) $fig['paid_pct'] ?>%"></b></div>
    <div class="bar-meta"><span><?= (int) $fig['paid_pct'] ?>% Paid</span><span><?= (int) $fig['verified_cnt'] ?> of 2 installments</span></div>
    <div class="rule"></div>
    <dl class="stats" style="margin:0">
      <div><dt>Quoted Price</dt><dd><?= e(peso($fig['total'])) ?></dd></div>
      <div><dt>Verified Payments</dt><dd><?= e(peso($fig['verified'])) ?></dd></div>
      <div><dt><?= (int) $order['downpayment_percent'] ?>% Down payment Required</dt><dd><?= e(peso($fig['dp_required'])) ?></dd></div>
      <div><dt>Balance Due Before Delivery</dt><dd><?= e(peso($fig['balance_after'])) ?></dd></div>
    </dl>
  </section>

  <?php if ($canPay): ?>
  <form class="panel" id="payForm" method="post" action="<?= e($self) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_PROOF_BYTES ?>">
    <input type="hidden" name="method" id="method" value="<?= e($selMethod) ?>">
    <h2>Submit <?= (int) $order['downpayment_percent'] ?>% Downpayment</h2>
    <p class="hint">Settle the required <?= (int) $order['downpayment_percent'] ?>% downpayment to confirm your order.</p>

    <div class="summary">
      <div class="row price"><span>Quoted Price</span><span><?= e(peso($fig['total'])) ?></span></div>
      <div class="row"><span><?= (int) $order['downpayment_percent'] ?>% Downpayment Required</span><span><?= e(peso($fig['dp_required'])) ?></span></div>
      <div class="row"><span>Downpayment Status</span><span><?= e($dpStatus) ?></span></div>
      <div class="divider-full"></div>
      <div class="topay"><span class="l">Amount to Pay</span><span class="r"><?= e(peso($fig['amount_due'])) ?></span></div>
    </div>

    <span class="field-label" id="pm">Payment Method</span>
    <div class="methods<?= bad($fieldErrors, 'method') ?>" role="radiogroup" aria-labelledby="pm">
      <button type="button" class="method<?= $selMethod === 'gcash' ? ' sel' : '' ?>" role="radio" aria-checked="<?= $selMethod === 'gcash' ? 'true' : 'false' ?>" data-m="gcash"><strong>GCASH</strong></button>
      <button type="button" class="method<?= $selMethod === 'bank' ? ' sel' : '' ?>" role="radio" aria-checked="<?= $selMethod === 'bank' ? 'true' : 'false' ?>" data-m="bank"><strong>Bank Transfer</strong><small>BPI/ BDO / UNIONBANK</small></button>
    </div>
    <?= field_err($fieldErrors, 'method') ?>

    <div class="cols">
      <div>
        <label class="field-label" for="amount">Payment Amount</label>
        <input class="inp locked" id="amount" name="amount" type="text" value="<?= e($amountVal) ?>" readonly aria-readonly="true" aria-describedby="amount-note">
        <span class="lock-note" id="amount-note">Fixed to your required downpayment</span>
      </div>
      <div>
        <label class="field-label" for="ref">Reference / Transaction No.</label>
        <input class="inp<?= bad($fieldErrors, 'reference') ?>" id="ref" name="reference" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="40" value="<?= e($refVal) ?>" required aria-describedby="err-reference">
        <?= field_err($fieldErrors, 'reference') ?>
      </div>
    </div>

    <span class="field-label">Proof of Payment (Screenshot / Receipt)</span>
    <label class="drop<?= bad($fieldErrors, 'proof') ?>" id="drop" tabindex="0">
      <input type="file" id="file" name="proof" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
      <span class="click">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
        <span><b>Click to upload</b> or drag &amp; drop</span>
      </span>
      <span class="types">JPG / PNG / PDF UP TO 5MB (REQUIRED FOR GCASH &amp; BANK TRANSFER</span>
      <span class="none" id="fname">No file chosen</span>
      <img class="preview" id="preview" alt="Preview of your proof of payment" hidden>
      <span class="pdfchip" id="pdfchip" hidden>&#128196; PDF selected</span>
      <span class="hint-tap" id="taphint" hidden>Tap the photo to enlarge</span>
      <button type="button" class="rm" id="rm" hidden>Remove</button>
    </label>
    <?= field_err($fieldErrors, 'proof') ?>

    <button class="submit" type="submit">Submit downpayment for verification</button>
  </form>
  <?php else: ?>
  <section class="panel" aria-label="Downpayment status">
    <h2><?= $fig['dp_paid'] ? 'Downpayment received' : 'Payment awaiting verification' ?></h2>
    <p class="notice"><?= $fig['dp_paid']
        ? 'Thank you! Your downpayment has been verified. The remaining balance is due 24 hours before your delivery slot.'
        : 'We received your payment details and are verifying them. This page will update once it is confirmed.' ?></p>
  </section>
  <?php endif; ?>
  </div><!-- /#pane-pay -->

  <section id="pane-history" class="hist" aria-label="Payment history" hidden>
    <h2>Payment History</h2>
    <?php if (!$payments): ?>
      <p class="empty">No payment history yet.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td><?= e(fmt_date($p['created_at'])) ?></td>
            <td><?= e($methodLabels[$p['method']] ?? $p['method']) ?></td>
            <td><?= e($p['reference_no']) ?></td>
            <td><?= e(peso($p['amount'])) ?></td>
            <td><span class="badge <?= e($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</main>

<footer>
  <div class="flinks">
    <a href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>ABOUT US</a>
    <i></i>
    <a href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="12" cy="10" r="2.5"/><path d="M7.5 17c.8-2 2.4-3 4.5-3s3.7 1 4.5 3"/></svg>CONTACTS</a>
  </div>
  <p class="copy">© 2019 R&amp;R Sweet Bites. All Rights Reserved.</p>
</footer>

<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Proof of payment, full size" hidden>
  <button type="button" class="x" id="lbClose" aria-label="Close">&times;</button>
  <img id="lbImg" alt="Proof of payment, full size">
</div>

<script>
  // Payment method toggle
  document.querySelectorAll('.method').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.querySelectorAll('.method').forEach(function(b){
        b.classList.remove('sel'); b.setAttribute('aria-checked','false');
      });
      btn.classList.add('sel'); btn.setAttribute('aria-checked','true');
      document.getElementById('method').value = btn.getAttribute('data-m');
    });
  });

  // File upload + drag & drop + preview (these elements only exist while the payment form is shown)
  var drop = document.getElementById('drop');
  var file = document.getElementById('file');
  var fname = document.getElementById('fname');
  var preview = document.getElementById('preview');
  var pdfchip = document.getElementById('pdfchip');
  var rm = document.getElementById('rm');
  if (drop && file && fname && preview && pdfchip && rm) {
    var objUrl = null;
    var form = document.getElementById('payForm');
    var refIn = document.getElementById('ref');
    var methodsBox = document.querySelector('.methods');

    // Inline field errors
    var fieldEl = { method: methodsBox, reference: refIn, proof: drop };
    function setErr(key, msg){
      var p = document.getElementById('err-' + key), el = fieldEl[key];
      if (!p) return;
      p.textContent = msg || ''; p.hidden = !msg;
      if (el) el.classList.toggle('bad', !!msg);
    }
    function validate(){
      var first = null, ok = true;
      function fail(key, msg){ setErr(key, msg); ok = false; if (!first) first = key; }
      ['method','reference','proof'].forEach(function(k){ setErr(k, ''); });

      if (['gcash','bank'].indexOf(document.getElementById('method').value) === -1) fail('method', 'Please choose a payment method.');

      if (!/^\d{4,40}$/.test(refIn.value.trim())) fail('reference', 'Reference / transaction number must be 4-40 digits (numbers only).');

      if (!file.files || !file.files[0]) fail('proof', 'Please upload your proof of payment.');

      if (first) {
        var target = first === 'proof' ? drop : first === 'method' ? methodsBox : fieldEl[first];
        target.scrollIntoView({behavior:'smooth', block:'center'});
        if (target.focus && first !== 'method') { try { target.focus({preventScroll:true}); } catch(e){} }
      }
      return ok;
    }
    if (form) form.addEventListener('submit', function(e){ if (!validate()) e.preventDefault(); });
    // Reference: numbers only, letters and symbols are silently ignored as they are typed or pasted
    refIn.addEventListener('input', function(){
      var before = refIn.value;
      var v = before.replace(/\D/g, '');
      if (v !== before) refIn.value = v;
      setErr('reference', '');
    });
    document.querySelectorAll('.method').forEach(function(b){ b.addEventListener('click', function(){ setErr('method', ''); }); });

    var taphint = document.getElementById('taphint');
    var lightbox = document.getElementById('lightbox');
    var lbImg = document.getElementById('lbImg');
    var lbClose = document.getElementById('lbClose');
    function openLightbox(){
      if (!preview.src) return;
      lbImg.src = preview.src; lightbox.hidden = false; lbClose.focus();
    }
    function closeLightbox(){ lightbox.hidden = true; lbImg.removeAttribute('src'); }
    preview.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); openLightbox(); });
    lightbox.addEventListener('click', closeLightbox);
    lbClose.addEventListener('click', function(e){ e.stopPropagation(); closeLightbox(); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !lightbox.hidden) closeLightbox(); });
    var OK_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];
    var MAX_BYTES = <?= MAX_PROOF_BYTES ?>;

    function clearPreview(msg){
      if (objUrl) { URL.revokeObjectURL(objUrl); objUrl = null; }
      preview.hidden = true; preview.removeAttribute('src');
      pdfchip.hidden = true; rm.hidden = true; taphint.hidden = true;
      fname.textContent = msg || 'No file chosen';
      fname.classList.toggle('err', !!msg);
    }
    function showFile(f){
      if (!f) { clearPreview(); return; }
      if (OK_TYPES.indexOf(f.type) === -1) { file.value = ''; clearPreview(); setErr('proof', 'Proof of payment must be a JPG, PNG or PDF file.'); return; }
      if (f.size > MAX_BYTES) { file.value = ''; clearPreview(); setErr('proof', 'Proof of payment must be 5 MB or smaller.'); return; }
      clearPreview(); setErr('proof', '');
      fname.textContent = f.name;
      if (f.type === 'application/pdf') {
        pdfchip.hidden = false;
      } else {
        objUrl = URL.createObjectURL(f);
        preview.src = objUrl; preview.hidden = false; taphint.hidden = false;
      }
      rm.hidden = false;
    }

    file.addEventListener('change', function(){
      showFile(file.files && file.files[0] ? file.files[0] : null);
    });
    rm.addEventListener('click', function(e){
      e.preventDefault(); e.stopPropagation();
      file.value = ''; clearPreview();
    });
    drop.addEventListener('keydown', function(e){
      if(e.target === drop && (e.key === 'Enter' || e.key === ' ')){ e.preventDefault(); file.click(); }
    });
    ['dragenter','dragover'].forEach(function(ev){
      drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave','drop'].forEach(function(ev){
      drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function(e){
      if(e.dataTransfer && e.dataTransfer.files.length){
        try { file.files = e.dataTransfer.files; } catch(err){}
        showFile(e.dataTransfer.files[0]);
      }
    });
  }

  // After a failed submit, bring the first problem field into view
  (function(){
    var first = document.querySelector('.ferr:not([hidden])');
    if (first) first.scrollIntoView({behavior:'smooth', block:'center'});
  })();

  // Tabs: Payment & Balance / Payment History
  document.querySelectorAll('.tabs button').forEach(function(tab){
    tab.addEventListener('click', function(){
      document.querySelectorAll('.tabs button').forEach(function(t){
        t.classList.remove('on'); t.setAttribute('aria-selected','false');
        document.getElementById(t.getAttribute('data-pane')).hidden = true;
      });
      tab.classList.add('on'); tab.setAttribute('aria-selected','true');
      document.getElementById(tab.getAttribute('data-pane')).hidden = false;
    });
  });
</script>
</body>
</html>
