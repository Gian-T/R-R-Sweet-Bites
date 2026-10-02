<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$pdo = db();

/* ---------- Load order (?order=ORD-2024-0423, falls back to a confirmed order) ---------- */
$orderNo = trim((string) ($_GET['order'] ?? ''));
$sql = 'SELECT o.*, c.initials AS client_initials, q.ref_no AS quote_ref
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        LEFT JOIN quotations q ON q.id = o.quotation_id ';
if ($orderNo !== '') {
    $st = $pdo->prepare($sql . 'WHERE o.order_no = ?');
    $st->execute([$orderNo]);
} else {
    $st = $pdo->query($sql . 'ORDER BY (o.stage >= 2) DESC, o.id ASC LIMIT 1');
}
$order = $st->fetch();
if (!$order) {
    not_found('Order');
}

/* ---------- Payment figures ---------- */
$st = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN status = 'verified' THEN amount END), 0) AS verified,
            COALESCE(SUM(CASE WHEN status = 'pending'  THEN amount END), 0) AS pending
     FROM payments WHERE order_id = ?"
);
$st->execute([$order['id']]);
$pay = $st->fetch();

$total     = (float) $order['total_amount'];
$dpPct     = (int) $order['downpayment_percent'];
$dpReq     = round($total * $dpPct / 100, 2);
$verified  = (float) $pay['verified'];
$pending   = (float) $pay['pending'];
$confirmed = $verified + 0.001 >= $dpReq;

if ($confirmed) {
    $paymentStatus = $dpPct . '% Deposit Received (' . peso($verified) . ')';
} elseif ($pending > 0) {
    $paymentStatus = 'Pending Verification (' . peso($pending) . ')';
} else {
    $paymentStatus = 'Awaiting ' . $dpPct . '% Deposit (' . peso(0) . ')';
}

/* ---------- Order journey tracker ---------- */
// orders.stage = the step currently in progress (1-5); 6 = every step finished.
// Until the downpayment is verified the tracker stays on step 1.
$stage = $confirmed ? max((int) $order['stage'], 2) : 1;
$steps = [
    1 => 'Payment Verified',
    2 => 'In Production',
    3 => 'Quality Check',
    4 => 'Ready for Delivery/Pick up',
    5 => 'Delivered',
];
function step_class(int $n, int $stage): string
{
    return $n < $stage ? 'done' : ($n === $stage ? 'current' : '');
}

$crumbRef = $order['quote_ref'] ?? $order['order_no'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $confirmed ? 'Order Confirmation' : 'Order Pending' ?> | R&amp;R Sweet Bites</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&family=Platypi:wght@400;500;600&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#FDF6F1; --bg-alt:#F8ECE4; --blush:#F6D8D6; --rose:#D97D8A;
  --ink:#4A2F2B; --muted:#8A6A62; --line:#F0D3CB; --white:#FFFFFF;
  --green:#829B7A; --red:#C65F62; --gold:#D6A85F;
  --font-head:'Playfair Display',Georgia,serif;
  --font-text:'Jost',system-ui,sans-serif;
  --font-btn:'Platypi',Georgia,serif;
  --pink:#D97D8A; --pink-light:#F6D8D6; --brown:#4A2F2B;
  --status-error:#C65F62;
  --color-primary:var(--pink); --brand-icon-bg:var(--pink-light); --color-text-dark:var(--brown);
  --font-brand:'Platypi',Georgia,serif; --font-nav:'Jost',system-ui,sans-serif;
  --text-sm:0.875rem; --text-base:1rem; --text-lg:1.35rem;
  --space-1:0.25rem; --space-2:0.5rem; --space-3:1rem; --space-5:2rem;
  --radius-full:50%; --shadow-sm:0 2px 10px rgba(0,0,0,0.03);
}
:root{padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}
html{scroll-padding-top:env(safe-area-inset-top,0px)}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg)}
body{font-family:var(--font-text);color:var(--ink);min-height:100vh;display:flex;flex-direction:column}
a{color:inherit;text-decoration:none}

/* ---------- Header ----------
   Phone layout: sizes & positions measured from the Figma frame
   (brand left, nav centred, bell + avatar right).
   >=900px: design-system sizes (1.35rem brand, 1rem nav, 2rem gaps, 5% margins). */
.topbar{
  line-height:1.3;
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


/* Main */
main{flex:1;width:100%;max-width:1100px;margin:0 auto;padding:18px 32px 80px}
.crumbs{font-size:11px;line-height:1.3;margin:0 0 0 40px;color:var(--ink)}
.crumbs a{color:var(--rose)}
.hero{text-align:center;margin-top:36px}
.check{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--green);background:#EAEFE6;display:grid;place-items:center;margin:0 auto 16px;color:var(--green)}
.check svg{width:18px;height:18px}
h1{font-family:var(--font-head);font-weight:700;font-size:28px;color:var(--ink)}
.hero p{margin-top:12px;font-size:12px;color:var(--muted)}

.card{background:var(--white);border:1px solid var(--line);border-radius:16px}
.tracker{margin-top:42px;padding:30px 34px 32px}
.eyebrow{font-size:9px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#7A5650}
.steps{display:flex;align-items:center;gap:12px;margin-top:18px;flex-wrap:nowrap;width:100%}
.step{flex:1 1 auto;justify-content:center;display:flex;align-items:center;gap:9px;padding:0 14px;height:36px;border-radius:8px;border:1px solid var(--line);background:#FBEAE4;font-size:9px;font-weight:600;color:var(--muted);white-space:nowrap}
.step .n{width:14px;height:14px;border-radius:50%;background:#fff;font-size:8px;display:grid;place-items:center;color:var(--muted)}
.step.done{background:#E9EFE5;border-color:var(--green);color:var(--ink)}
.step.done .n{background:var(--green);color:#fff}
.step.current{background:#FBF1DF;border-color:var(--gold);color:var(--ink)}
.step.current .n{background:var(--gold);color:#fff}
.sep{flex:0 0 auto;font-size:11px;color:var(--muted)}

.summary{margin-top:32px;max-width:347px;padding:20px 17px 20px}
.sum-head{display:flex;justify-content:space-between;align-items:center}
.sum-head h2{font-family:var(--font-head);font-weight:600;font-size:15px;color:var(--ink)}
.tag{background:#E9EFE5;color:var(--green);font-size:8px;font-weight:600;letter-spacing:.05em;padding:3px 8px;border-radius:5px}
.rows{margin-top:20px;display:grid;gap:11px}
.row{display:flex;justify-content:space-between;font-size:9px}
.row span{color:var(--muted)}
.row b{font-weight:600;color:var(--ink)}
.divider{border:0;border-top:1px solid var(--line);margin:18px 0 12px}
.style-label{font-size:7px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#7A5650}
.style-text{margin-top:6px;font-size:9px;font-weight:600;line-height:1.55;color:var(--ink)}

/* Footer */
footer{background:var(--line);text-align:center;padding:18px 16px 14px}
.flinks{display:flex;justify-content:center;align-items:center;gap:10px;font-size:12px;font-weight:500;color:var(--ink)}
.flinks a{display:flex;align-items:center;gap:6px}
.flinks svg{width:13px;height:13px}
.flinks i{width:1px;height:14px;background:var(--ink)}
.copy{margin-top:3px;font-size:12px;color:var(--ink)}

@media(max-width:820px){
  main{padding:8px 16px 56px}
  .crumbs{margin-left:0}
  .tracker{padding:22px 16px}
  .sep{display:none}
  .steps{flex-wrap:wrap}
  .step{flex:1 1 100%}
  .summary{max-width:100%}
}
.tag.pending-tag{background:rgba(214,168,95,.2);color:#B98733}
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

<main>
  <p class="crumbs"><a href="#">My Orders</a> / Quotation Detail / #<?= e($crumbRef) ?></p>

  <section class="hero">
    <div class="check" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
    </div>
    <?php if ($confirmed): ?>
    <h1>Your Order is Confirmed!</h1>
    <p>Thank you, <?= e($order['client_initials']) ?>! We will now proceed with making your cake.</p>
    <?php else: ?>
    <h1>Waiting for Your Downpayment</h1>
    <p>Hi <?= e($order['client_initials']) ?>! We will start on your cake once your <?= $dpPct ?>% downpayment is verified.</p>
    <?php endif; ?>
  </section>

  <section class="card tracker" aria-label="Order journey tracker">
    <div class="eyebrow">Order Journey Tracker</div>
    <div class="steps">
      <?php foreach ($steps as $n => $label): ?>
      <div class="step <?= step_class($n, $stage) ?>"><span class="n"><?= $n ?></span><?= e($label) ?></div><?php if ($n < count($steps)): ?><span class="sep">&gt;</span><?php endif; ?>

      <?php endforeach; ?>
    </div>
  </section>

  <section class="card summary" aria-label="Order summary">
    <div class="sum-head">
      <h2>Order Summary</h2>
      <span class="tag<?= $confirmed ? '' : ' pending-tag' ?>"><?= $confirmed ? 'CONFIRMED' : 'PENDING' ?></span>
    </div>
    <div class="rows">
      <div class="row"><span>Order Number</span><b>#<?= e($order['order_no']) ?></b></div>
      <div class="row"><span>Order Date</span><b><?= e(fmt_date($order['order_date'])) ?></b></div>
      <div class="row"><span>Payment Status</span><b><?= e($paymentStatus) ?></b></div>
      <div class="row"><span>Total Quotation Amount</span><b><?= e(peso($total)) ?></b></div>
      <div class="row"><span>Est. Delivery Date</span><b><?= e(fmt_date($order['est_delivery_date'])) ?></b></div>
    </div>
    <hr class="divider">
    <div class="style-label">Selected Cake Style</div>
    <p class="style-text"><?= e($order['cake_style']) ?></p>
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

</body>
</html>
