<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$pdo = db();

/* ---------- Load quotation (?ref=Q-2024-0847, falls back to the latest one) ---------- */
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

$ref   = trim((string) ($_GET['ref'] ?? ''));
$quote = load_quote($pdo, $ref);
if (!$quote) {
    not_found('Quotation');
}
$self = 'quotation-composer.php?ref=' . urlencode($quote['ref_no']);

/* ---------- Handle Accept / Request Revision ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $errors = [];       // general messages (shown in the banner)
    $fieldErrors = [];  // messages tied to one field (shown under that field)

    if (!csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
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
            flash_ok('Quotation accepted. Thank you!');
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
                flash_ok('Revision requested. We will send you an updated quotation once it has been reviewed.');
            }
        }
        ($errors || $fieldErrors) ? $pdo->rollBack() : $pdo->commit();
    }

    if ($errors || $fieldErrors) {
        flash_errors($errors);
        $_SESSION['field_errors'] = $fieldErrors;
        $_SESSION['old_revision'] = (string) ($_POST['message'] ?? '');
    }
    redirect($self); // Post/Redirect/Get: refreshing the page will not resubmit
}

/* ---------- View data ---------- */
$st = $pdo->prepare('SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order, id');
$st->execute([$quote['id']]);
$items = $st->fetchAll();

$grand = 0.0;
foreach ($items as &$it) {
    $it['line_total'] = ($it['qty'] ?? 1) * (float) $it['unit_price'];
    $grand += $it['line_total'];
}
unset($it);

$left    = days_left($quote['valid_until']);
$expired = $left < 0;
$status  = ($quote['status'] === 'issued' && $expired) ? 'expired' : $quote['status'];
$canAct  = ($status === 'issued');

$statusLabels = [
    'issued'             => 'Quotation Issued',
    'accepted'           => 'Accepted',
    'revision_requested' => 'Revision Requested',
    'expired'            => 'Expired',
];
$validityText = $expired ? 'Expired' : ($left === 0 ? 'Expires today' : $left . ($left === 1 ? ' Day' : ' Days') . ' Remaining');
$expiryLine   = $expired ? 'Quotation expired on ' : 'Quotation expires on ';

// The most recent revision the customer sent (shown back to them once the form is locked)
$st = $pdo->prepare('SELECT message, created_at FROM quotation_revisions WHERE quotation_id = ? ORDER BY id DESC LIMIT 1');
$st->execute([$quote['id']]);
$latestRev = $st->fetch() ?: null;

$oldRevision = (string) ($_SESSION['old_revision'] ?? '');
unset($_SESSION['old_revision']);
$fieldErrors = $_SESSION['field_errors'] ?? [];
unset($_SESSION['field_errors']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Quotation <?= e($quote['ref_no']) ?> – R&amp;R Sweet Bites</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600&family=Platypi:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
<style>
/* calc(1*var(--u)) = 1px of the Figma frame (810px wide). The whole layout scales with the viewport. */
:root{
  --u:clamp(1px,calc((100vw - 16px)/810),1.3px);
  --footer:#F0D3CB; --bg:#FDF6F1; --alt:#F8ECE4; --tag:#F6D8D6; --rose:#D97D8A; --ink:#4A2F2B;
  --muted:#8A6A62; --line:#F0D3CB; --white:#FFFFFF; --ok:#829B7A; --err:#C65F62; --pending:#D6A85F;
  --color-primary:var(--rose); --brand-icon-bg:var(--tag); --color-text-dark:var(--ink);
  --font-brand:'Platypi',Georgia,serif; --font-nav:'Jost',system-ui,sans-serif;
  --text-sm:0.875rem; --text-base:1rem; --text-lg:1.35rem;
  --space-1:0.25rem; --space-2:0.5rem; --space-3:1rem; --space-5:2rem;
  --radius-full:50%; --shadow-sm:0 2px 10px rgba(0,0,0,0.03); --status-error:#C65F62;
  padding-top:env(safe-area-inset-top,0px); padding-bottom:env(safe-area-inset-bottom,0px);
  --head:'Playfair Display',Georgia,serif; --text:'Jost',system-ui,sans-serif; --btn:'Platypi',Georgia,serif;
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-padding-top:env(safe-area-inset-top,0px);-webkit-text-size-adjust:none;background:var(--bg)}
body{font-family:var(--text);color:var(--ink);background:var(--bg);min-height:100vh;display:flex;flex-direction:column;font-size:calc(8*var(--u))}
a{color:inherit;text-decoration:none}
.frame{width:calc(810*var(--u));margin:0 auto}

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

/* MAIN */
main{flex:1}
main .frame{padding:calc(14*var(--u)) calc(42*var(--u)) calc(27*var(--u)) calc(40*var(--u))}
.crumbs{font-size:calc(9*var(--u));line-height:calc(12*var(--u));padding-left:calc(24*var(--u));margin-bottom:calc(15*var(--u))}
.crumbs a{color:var(--rose)}
.layout{display:grid;grid-template-columns:calc(481*var(--u)) calc(219*var(--u));gap:calc(28*var(--u));align-items:start}

/* QUOTATION CARD */
.card{background:var(--white);border-radius:calc(12*var(--u));padding:calc(23*var(--u)) calc(19*var(--u)) calc(20*var(--u))}
.top-row{display:flex;justify-content:space-between;align-items:center;height:calc(14*var(--u))}
.tag{background:rgba(214,168,95,.2);color:var(--pending);font-size:calc(6.3*var(--u));font-weight:500;letter-spacing:.04em;padding:0 calc(7*var(--u));height:calc(14*var(--u));line-height:calc(14*var(--u));border-radius:calc(7*var(--u));text-transform:uppercase}
.date{font-size:calc(8*var(--u));font-weight:500}
h1{font:600 calc(17.5*var(--u))/calc(24*var(--u)) var(--head);margin-top:calc(8*var(--u))}
.ref{font-size:calc(9*var(--u));line-height:calc(12*var(--u));font-weight:500;color:var(--rose);margin-top:calc(2*var(--u))}
hr{border:0;border-top:1px solid var(--line)}
.card>hr.a{margin-top:calc(20*var(--u))}
h2{font:500 calc(11.5*var(--u))/calc(16*var(--u)) var(--head);margin:calc(13*var(--u)) 0 calc(14*var(--u)) calc(18*var(--u))}
.specs{display:grid;grid-template-columns:1fr 1fr;row-gap:calc(10*var(--u));column-gap:calc(12*var(--u));padding-left:calc(18*var(--u))}
.spec{background:var(--bg);border-radius:calc(5*var(--u));height:calc(34*var(--u));width:calc(96*var(--u));padding:0 calc(9*var(--u));display:flex;flex-direction:column;justify-content:center;gap:calc(4*var(--u))}
.spec small{display:block;font-size:calc(6*var(--u));line-height:calc(8*var(--u));font-weight:600;letter-spacing:.05em;color:var(--muted);text-transform:uppercase}
.spec span{display:block;font-size:calc(8*var(--u));line-height:calc(11*var(--u));font-weight:500}
.card>hr.b{margin-top:calc(7*var(--u))}
table{width:100%;border-collapse:collapse;margin-top:calc(10*var(--u));table-layout:fixed}
th{background:var(--alt);height:calc(16*var(--u));font-size:calc(6.3*var(--u));font-weight:600;letter-spacing:.04em;color:var(--muted);text-transform:uppercase;text-align:right;padding:0 calc(6*var(--u)) 0 0}
td{height:calc(29.2*var(--u));font-size:calc(8*var(--u));font-weight:500;text-align:right;padding:0 calc(6*var(--u)) 0 0;border-bottom:1px solid var(--line)}
th:first-child,td:first-child{text-align:left;padding-left:calc(7*var(--u))}
th.q,td.q{width:calc(40*var(--u));text-align:center;padding:0}
th.u,td.u{width:calc(54*var(--u));padding-right:0}
th.t,td.t{width:calc(62*var(--u))}
td.t{font-weight:600}
.total{display:flex;justify-content:flex-end;align-items:center;gap:calc(69*var(--u));height:calc(30*var(--u));margin-top:calc(7*var(--u));padding-right:calc(11*var(--u))}
.total strong{font-size:calc(9.5*var(--u));font-weight:600}
.total span{font:500 calc(14*var(--u)) var(--head);color:var(--rose)}
.card>hr.c{margin-top:calc(15*var(--u))}
.rev label{display:block;font-size:calc(8*var(--u));line-height:calc(12*var(--u));font-weight:600;margin:calc(18*var(--u)) 0 calc(4*var(--u))}
textarea{display:block;width:100%;height:calc(57*var(--u));border:1px solid var(--line);border-radius:calc(6*var(--u));padding:calc(8*var(--u)) calc(7*var(--u));font:400 calc(8*var(--u))/calc(11*var(--u)) var(--text);color:var(--ink);resize:none;background:#fff}
textarea::placeholder{color:var(--muted)}
textarea:focus{outline:2px solid var(--rose);outline-offset:1px}
.rev-actions{display:flex;justify-content:space-between;align-items:flex-end;margin-top:calc(12*var(--u));height:calc(24*var(--u))}
.rev-actions p{font-size:calc(6.8*var(--u));line-height:calc(9*var(--u));color:var(--muted);padding-bottom:calc(5*var(--u))}
.btns{display:flex;gap:calc(8*var(--u))}
.btn{font:500 calc(7.5*var(--u)) var(--btn);height:calc(24*var(--u));border-radius:calc(12*var(--u));cursor:pointer;transition:filter .15s}
.btn.out{width:calc(95*var(--u));background:#fff;border:1px solid var(--rose);color:var(--rose)}
.btn.fill{width:calc(118*var(--u));background:var(--rose);border:1px solid var(--rose);color:#fff}
.btn:hover{filter:brightness(.95)}
.btn:focus-visible{outline:2px solid var(--ink);outline-offset:2px}

/* SIDEBAR */
aside{display:flex;flex-direction:column}
.status{background:var(--alt);border-radius:calc(12*var(--u));padding:calc(14*var(--u)) calc(12*var(--u)) calc(12*var(--u));height:calc(156*var(--u))}
h3{font:600 calc(11.5*var(--u))/calc(16*var(--u)) var(--head)}
.status dl{margin-top:calc(8*var(--u))}
.row{display:flex;justify-content:space-between;align-items:center;height:calc(18*var(--u));font-size:calc(7.5*var(--u));color:var(--muted)}
.row.last{margin-top:calc(3*var(--u))}
.row b{color:var(--ink);font-weight:600}
.pill{background:rgba(214,168,95,.25);color:var(--pending);font-size:calc(7*var(--u));font-weight:500;height:calc(14*var(--u));line-height:calc(14*var(--u));padding:0 calc(9*var(--u));border-radius:calc(7*var(--u))}
.status hr{margin-top:calc(8*var(--u));border-top-color:#EBCFC6}
.expiry{display:flex;align-items:center;gap:calc(5*var(--u));font-size:calc(7*var(--u));line-height:calc(12*var(--u));margin-top:calc(10*var(--u));color:var(--muted)}
.expiry svg{width:calc(9*var(--u));height:calc(9*var(--u));color:var(--rose);margin-left:calc(1*var(--u))}
.side{background:#fff;border:1px solid var(--line);border-radius:calc(12*var(--u))}
.notes{margin-top:calc(18*var(--u));height:calc(83*var(--u));padding:calc(15*var(--u)) calc(17*var(--u))}
.who{display:flex;align-items:center;gap:calc(10*var(--u))}
.ab{width:calc(20*var(--u));height:calc(20*var(--u));border-radius:50%;background:var(--tag);display:grid;place-items:center;font-size:calc(7*var(--u));font-weight:600}
.who h3{font-size:calc(10.5*var(--u))}
.notes p{font-size:calc(7.5*var(--u));line-height:calc(12*var(--u));color:var(--muted);margin:calc(9*var(--u)) 0 0 calc(4*var(--u))}
.terms{margin-top:calc(8*var(--u));padding:calc(11*var(--u)) calc(10*var(--u)) calc(12*var(--u)) calc(17*var(--u))}
.terms summary{list-style:none;display:flex;justify-content:space-between;align-items:center;height:calc(14*var(--u));font-size:calc(7*var(--u));font-weight:600;cursor:pointer}
.terms summary::-webkit-details-marker{display:none}
.terms summary::after{content:"";width:calc(4*var(--u));height:calc(4*var(--u));border-right:1.5px solid var(--rose);border-bottom:1.5px solid var(--rose);transform:rotate(45deg) translate(-1px,-1px);margin-right:calc(6*var(--u));transition:transform .2s}
.terms[open] summary::after{transform:rotate(-135deg)}
.terms p{font-size:calc(6.4*var(--u));line-height:calc(9.5*var(--u));color:var(--muted);margin-top:calc(5*var(--u));max-width:calc(182*var(--u))}

/* FOOTER (same as customer-orders.html) */
/* Footer (same as order-confirmation.html) */
footer{background:var(--line);text-align:center;padding:18px 16px 14px}
.flinks{display:flex;justify-content:center;align-items:center;gap:10px;font-size:12px;font-weight:500;color:var(--ink)}
.flinks a{display:flex;align-items:center;gap:6px}
.flinks svg{width:13px;height:13px}
.flinks i{width:1px;height:14px;background:var(--ink)}
.copy{margin-top:3px;font-size:12px;color:var(--ink)}

/* MOBILE */
@media(max-width:960px){
  :root{--u:1.6px}
  .frame{width:100%}
  main .frame{padding:8px 16px 24px}
  .crumbs{padding-left:0}
  .layout{grid-template-columns:1fr}
  .specs{grid-template-columns:1fr 1fr}
  .total{gap:30px}
  .rev-actions{height:auto;flex-direction:column;align-items:flex-start;gap:10px}
  .rev-actions p{padding:0}
  .status{height:auto}
}
<?= FLASH_CSS ?>
.btn:disabled{opacity:.5;cursor:not-allowed;filter:none}
textarea:disabled{background:var(--bg)}
/* inline field error */
.ferr{display:flex;align-items:flex-start;gap:calc(5*var(--u));margin-top:calc(5*var(--u));font-size:calc(8*var(--u));line-height:calc(11*var(--u));font-weight:500;color:var(--err)}
.ferr::before{content:"!";flex:none;width:calc(11*var(--u));height:calc(11*var(--u));border-radius:50%;background:var(--err);color:#fff;font-size:calc(8*var(--u));font-weight:700;display:grid;place-items:center}
.ferr[hidden]{display:none}
textarea.bad{border-color:var(--err);background:#FFF8F8}
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
    <span class="avatar"><?= e($quote['client_initials']) ?></span>
  </div>
</header>

<main>
  <div class="frame">
    <div class="crumbs"><a href="#">My Orders</a> / Quotation Detail / #<?= e($quote['ref_no']) ?></div>
    <?php render_flash('margin-left:calc(24*var(--u))'); ?>

    <div class="layout">
      <section class="card" aria-label="Quotation">
        <div class="top-row"><span class="tag"><?= e($statusLabels[$status]) ?></span><span class="date">Date Issued: <?= e(fmt_date($quote['issued_at'])) ?></span></div>
        <h1>Your Custom Cake Quotation</h1>
        <div class="ref">Reference No: #<?= e($quote['ref_no']) ?></div>
        <hr class="a">
        <h2>Design Specifications Summary</h2>
        <div class="specs">
          <div class="spec"><small>Cake Type</small><span><?= e($quote['cake_type']) ?></span></div>
          <div class="spec"><small>Delivery Requested</small><span><?= e(fmt_date($quote['delivery_date'])) ?></span></div>
          <div class="spec"><small>Cake Size</small><span><?= e($quote['cake_size']) ?></span></div>
        </div>
        <hr class="b">
        <table>
          <thead><tr><th>Line Item Description</th><th class="q">Qty</th><th class="u">Unit Price</th><th class="t">Total</th></tr></thead>
          <tbody>
            <?php foreach ($items as $it): ?>
            <tr><td><?= e($it['description']) ?></td><td class="q"><?= $it['qty'] === null ? '—' : (int) $it['qty'] ?></td><td class="u"><?= e(peso($it['unit_price'])) ?></td><td class="t"><?= e(peso($it['line_total'])) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="total"><strong>Total Amount</strong><span><?= e(peso($grand)) ?></span></div>
        <hr class="c">
        <form class="rev" method="post" action="<?= e($self) ?>">
          <?= csrf_field() ?>
          <label for="rev"><?= ($status === 'revision_requested' && $latestRev)
              ? 'Your Revision Request &middot; sent ' . e(fmt_date($latestRev['created_at']))
              : 'Need adjustments? Request a Revision' ?></label>
          <textarea id="rev" name="message" maxlength="1000" class="<?= isset($fieldErrors['message']) ? 'bad' : '' ?>" aria-describedby="err-message" placeholder="<?= $canAct ? 'Ex: &quot;Could we change the frosting to... &quot;' : ($status === 'accepted' ? 'You accepted this quotation.' : 'This quotation is closed.') ?>"<?= $canAct ? '' : ' disabled' ?>><?= e($canAct ? $oldRevision : ($latestRev['message'] ?? '')) ?></textarea>
          <p class="ferr" id="err-message" role="alert"<?= isset($fieldErrors['message']) ? '' : ' hidden' ?>><?= e($fieldErrors['message'] ?? '') ?></p>
          <div class="rev-actions">
            <p>* We will send you an updated quotation once your revision has been reviewed.</p>
            <div class="btns">
              <button class="btn out" type="submit" name="action" value="revision"<?= $canAct ? '' : ' disabled' ?>>Request Revision</button>
              <button class="btn fill" type="submit" name="action" value="accept"<?= $canAct ? ' onclick="return confirm(\'Accept this quotation?\')"' : ' disabled' ?>>Accept Quotation</button>
            </div>
          </div>
        </form>
      </section>

      <aside>
        <div class="status">
          <h3>Quotation Status</h3>
          <dl>
            <div class="row"><span>Reference ID</span><b><?= e($quote['reference_id']) ?></b></div>
            <div class="row"><span>Client</span><b><?= e($quote['client_initials']) ?></b></div>
            <div class="row"><span>Complexity Score</span><span class="pill"><?= e($quote['complexity']) ?></span></div>
            <div class="row last"><span>Quote Validity</span><b><?= e($validityText) ?></b></div>
          </dl>
          <hr>
          <div class="expiry"><svg viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M5 1v8M1 5h8"/></svg><?= e($expiryLine . fmt_date($quote['valid_until'])) ?>.</div>
        </div>

        <div class="side notes">
          <div class="who"><div class="ab"><?= e($quote['admin_initials']) ?></div><h3>Notes from <?= e($quote['admin_initials']) ?></h3></div>
          <p><?= $quote['admin_notes'] !== null && $quote['admin_notes'] !== '' ? '&ldquo;' . e($quote['admin_notes']) . '&rdquo;' : 'No notes yet.' ?></p>
        </div>

        <details class="side terms">
          <summary>Terms &amp; Booking Conditions</summary>
          <p>20% upfront payment secures your slot. Outstanding balances must be cleared 24h prior to selected delivery slot.</p>
        </details>
      </aside>
    </div>
  </div>
</main>

<footer>
  <div class="flinks">
    <a href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>ABOUT US</a>
    <i></i>
    <a href="#"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="12" cy="10" r="2.5"/><path d="M7.5 17c.8-2 2.4-3 4.5-3s3.7 1 4.5 3"/></svg>CONTACTS</a>
  </div>
  <p class="copy">© 2019 R&amp;R Sweet Bites. All Rights Reserved.</p>
</footer>

<script>
  // Show a problem right under the revision box, before anything is sent
  (function(){
    var form = document.querySelector('form.rev');
    var box = document.getElementById('rev');
    var err = document.getElementById('err-message');
    if (!form || !box || !err) return;

    function setErr(msg){
      err.textContent = msg || ''; err.hidden = !msg;
      box.classList.toggle('bad', !!msg);
    }
    form.addEventListener('submit', function(e){
      var btn = e.submitter;
      if (!btn || btn.value !== 'revision') return;      // "Accept Quotation" needs no message
      var len = box.value.trim().length;
      if (len < 5) {
        e.preventDefault();
        setErr('Please describe the change you would like (at least 5 characters).');
        box.scrollIntoView({behavior:'smooth', block:'center'});
        box.focus({preventScroll:true});
      } else if (len > 1000) {
        e.preventDefault();
        setErr('Revision notes are limited to 1000 characters.');
      }
    });
    box.addEventListener('input', function(){ setErr(''); });

    // After a failed submit, bring the problem into view
    if (!err.hidden) err.scrollIntoView({behavior:'smooth', block:'center'});
  })();
</script>

</body>
</html>
