<?php

declare(strict_types=1);

session_start();
if (empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['customer_csrf'];
$pageId = $customerPage ?? 'home';
$publicPages = ['login', 'register'];
$isSignedIn = !empty($_SESSION['customer_id']);
if (!in_array($pageId, $publicPages, true) && !$isSignedIn) {
    header('Location: login.php');
    exit;
}
if ($isSignedIn && in_array($pageId, $publicPages, true)) {
    header('Location: home.php');
    exit;
}

$pdo = null;
$dbMessage = '';
try {
    require_once __DIR__ . '/../../VS-CODE-R&R/includes/db_connect.php';
} catch (Throwable $exception) {
    error_log('Customer frontend database connection error: ' . $exception->getMessage());
  $dbMessage = 'The database is not available. Start MySQL, check the connection settings, and import CustomerPage/database/customer_page.sql.';
}

function customerEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function moneyValue(string|float|int|null $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

function customerStatus(string $status): string
{
    $pending = ['pending', 'quoted', 'in_production', 'quality_check'];
    $class = in_array($status, $pending, true) ? 'pending' : ($status === 'cancelled' ? 'cancelled' : '');
    return '<span class="status ' . $class . '">' . customerEscape(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$customerName = (string) ($_SESSION['customer_name'] ?? 'Customer');
$customerEmail = (string) ($_SESSION['customer_email'] ?? '');
$customer = ['full_name' => $customerName, 'email' => $customerEmail, 'phone' => ''];
$orders = [];
$gallery = [];
$payments = [];
$availability = [];
$myOrder = null;

if ($pdo instanceof PDO) {
  if (in_array($pageId, $publicPages, true)) {
    try {
      $pdo->query('SELECT customer_id FROM customer_portal_customers LIMIT 0');
    } catch (Throwable $exception) {
      error_log('Customer account schema check error: ' . $exception->getMessage());
      $dbMessage = 'Customer tables are missing. Import CustomerPage/database/customer_page.sql into the database configured for this site.';
    }
  }

    try {
        if ($isSignedIn) {
            $statement = $pdo->prepare('SELECT full_name, email, phone FROM customer_portal_customers WHERE customer_id = :id');
            $statement->execute(['id' => $customerId]);
            $customer = $statement->fetch() ?: $customer;
      if (in_array($pageId, ['home', 'orders', 'order', 'fulfillment', 'feedback', 'cancel'], true)) {
        $statement = $pdo->prepare('SELECT * FROM customer_portal_orders WHERE customer_id = :id ORDER BY created_at DESC');
        $statement->execute(['id' => $customerId]);
        $orders = $statement->fetchAll();
      }
      if ($pageId === 'payments') {
        $statement = $pdo->prepare('SELECT p.*, o.order_number FROM customer_order_payments p JOIN customer_portal_orders o ON o.order_id = p.order_id WHERE o.customer_id = :id ORDER BY p.paid_at DESC');
        $statement->execute(['id' => $customerId]);
        $payments = $statement->fetchAll();
      }
        }
    if ($pageId === 'gallery') {
      $gallery = $pdo->query('SELECT image_url, title, description, category, occasion FROM gallery_item ORDER BY created_at DESC, gallery_id DESC LIMIT 12')->fetchAll();
    }
    if ($pageId === 'availability') {
      $availability = $pdo->query('SELECT availability_date, availability_status, note FROM customer_weekly_availability WHERE availability_date >= CURRENT_DATE ORDER BY availability_date LIMIT 35')->fetchAll();
    }
        if ($isSignedIn && isset($_GET['id'])) {
            $statement = $pdo->prepare('SELECT * FROM customer_portal_orders WHERE order_id = :order_id AND customer_id = :customer_id');
            $statement->execute(['order_id' => (int) $_GET['id'], 'customer_id' => $customerId]);
            $myOrder = $statement->fetch() ?: null;
        }
    } catch (Throwable $exception) {
        error_log('Customer portal read error: ' . $exception->getMessage());
        $dbMessage = 'Customer data for this page is not ready. Import the customer SQL and confirm the required gallery schema is installed.';
    }
}

$brandLogo = '../../VS-CODE-R%26R/Images-Icons/R%26R%20Logo.jpg';
$authPage = in_array($pageId, $publicPages, true);
$pageTitles = [
    'home' => 'My Cake Orders', 'gallery' => 'Cake Design Gallery', 'availability' => 'Event Date Availability',
    'request' => 'Custom Cake Request', 'orders' => 'My Cake Orders', 'order' => 'Order Details',
    'fulfillment' => 'Fulfillment & Delivery', 'payments' => 'Payment History', 'feedback' => 'Customer Feedback',
    'quote' => 'Your Cake Quotation', 'cancel' => 'Cancel Order Request', 'profile' => 'Manage Your Profile',
    'login' => 'Customer Login', 'register' => 'Customer Registration',
];
$title = $pageTitles[$pageId] ?? 'R&R Sweet Bites';
$navItems = [
    'home' => ['Home', 'home.php'], 'gallery' => ['Gallery', 'gallery.php'], 'request' => ['Submit Request', 'request.php'],
    'orders' => ['My Order', 'orders.php'], 'feedback' => ['Messages', 'feedback.php'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#fff7f2">
  <title><?= customerEscape($title) ?> | R&amp;R Sweet Bites</title>
  <link rel="stylesheet" href="assets/customer.css">
</head>
<body>
<?php if ($authPage): ?>
  <main class="auth-shell">
    <div class="auth-card">
      <section class="auth-art">
        <img src="<?= $brandLogo ?>" alt="R&amp;R Sweet Bites cake logo">
        <h2><?= $pageId === 'login' ? 'Welcome back!' : 'Join R&amp;R Sweet Bites' ?></h2>
        <p><?= $pageId === 'login' ? 'Log in to track orders, view quotations, and manage your requests.' : 'Register to submit custom cake requests, get pricing, and track your order.' ?></p>
      </section>
      <section class="auth-form">
        <p class="eyebrow"><?= $pageId === 'login' ? 'Customer login' : 'Create account' ?></p>
        <h1><?= $pageId === 'login' ? 'Access Your Account' : 'Customer Registration' ?></h1>
        <?php if ($dbMessage !== ''): ?><p class="message error"><?= customerEscape($dbMessage) ?></p><?php endif; ?>
        <form method="post" action="../backend/auth.php" data-async>
          <input type="hidden" name="csrf_token" value="<?= customerEscape($csrf) ?>">
          <input type="hidden" name="action" value="<?= $pageId === 'login' ? 'login' : 'register' ?>">
          <?php if ($pageId === 'register'): ?>
            <div class="field"><label for="full-name">Full name</label><input id="full-name" name="full_name" maxlength="120" autocomplete="name" required></div>
          <?php endif; ?>
          <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" required></div>
          <?php if ($pageId === 'register'): ?><div class="field"><label for="phone">Contact number</label><input id="phone" name="phone" type="tel" maxlength="40" autocomplete="tel" required></div><?php endif; ?>
          <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" minlength="8" autocomplete="<?= $pageId === 'login' ? 'current-password' : 'new-password' ?>" required></div>
          <?php if ($pageId === 'register'): ?><div class="field"><label for="password-confirm">Confirm password</label><input id="password-confirm" name="password_confirm" type="password" minlength="8" autocomplete="new-password" required></div><?php endif; ?>
          <?php if ($pageId === 'login'): ?><label class="small"><input type="checkbox" name="remember"> Remember me</label><?php endif; ?>
          <p class="message" data-message role="status" hidden></p>
          <button class="button" type="submit"><?= $pageId === 'login' ? 'Log in' : 'Create account' ?></button>
        </form>
        <p class="auth-foot"><?php if ($pageId === 'login'): ?>Don’t have an account? <a href="register.php">Register</a><?php else: ?>Already have an account? <a href="login.php">Log in</a><?php endif; ?></p>
      </section>
    </div>
  </main>
<?php else: ?>
  <header class="topbar">
    <a class="brand" href="home.php"><img src="<?= $brandLogo ?>" alt=""><span>R&amp;R Sweet Bites</span></a>
    <button class="mobile-toggle" type="button" aria-label="Toggle navigation">☰</button>
    <nav class="nav" aria-label="Customer navigation">
      <?php foreach ($navItems as $key => [$label, $href]): ?><a class="<?= $pageId === $key ? 'active' : '' ?>" href="<?= $href ?>"><?= $label ?></a><?php endforeach; ?>
      <a class="<?= $pageId === 'availability' ? 'active' : '' ?>" href="availability.php">Availability</a>
    </nav>
    <div class="nav-actions"><span class="small">Hi, <?= customerEscape(explode(' ', $customerName)[0]) ?></span><div class="dropdown"><button class="avatar" type="button" aria-label="Account menu"><?= customerEscape(strtoupper(substr($customerName, 0, 1))) ?></button><div class="dropdown-menu"><a href="profile.php">Account settings</a><a href="payments.php">Payment history</a><button type="button" data-logout="../backend/auth.php" data-csrf="<?= customerEscape($csrf) ?>">Log out</button></div></div></div>
  </header>
  <main class="page <?= in_array($pageId, ['request', 'profile', 'fulfillment', 'feedback', 'cancel'], true) ? 'page-narrow' : '' ?>">
    <?php if ($dbMessage !== ''): ?><p class="message error"><?= customerEscape($dbMessage) ?></p><?php endif; ?>
    <?php if ($pageId === 'home'): ?>
      <section class="hero-home"><div><p class="eyebrow">Your customer space</p><h1>Welcome, <?= customerEscape(explode(' ', $customerName)[0]) ?>.</h1><p>Keep your celebrations moving. Review your orders, find inspiration, or send us a new cake idea.</p><div class="nav-actions"><a class="button" href="request.php">Start a cake request</a><a class="button-secondary" href="orders.php">View my orders</a></div></div><img class="hero-logo" src="<?= $brandLogo ?>" alt="R&amp;R Sweet Bites"></section>
      <section class="grid grid-3" style="margin:20px 0"><div class="metric"><span>My orders</span><strong><?= count($orders) ?></strong></div><div class="metric"><span>In progress</span><strong><?= count(array_filter($orders, static fn(array $order): bool => in_array($order['order_status'], ['confirmed','in_production','quality_check','ready'], true))) ?></strong></div><div class="metric"><span>Paid to date</span><strong><?= moneyValue(array_sum(array_map(static fn(array $order): float => (float) $order['amount_paid'], $orders))) ?></strong></div></section>
      <div class="layout-main"><section class="panel"><div class="panel-title"><h2>Recent orders</h2><a class="account-link" href="orders.php">See all</a></div><?php if ($orders === []): ?><div class="empty">No customer orders yet. Send a cake request to get started.</div><?php else: foreach (array_slice($orders, 0, 3) as $order): ?><div class="order-row"><div><strong><?= customerEscape($order['cake_name']) ?></strong><p>Order #<?= customerEscape($order['order_number']) ?></p></div><?= customerStatus($order['order_status']) ?><a href="order.php?id=<?= (int) $order['order_id'] ?>">Details</a></div><?php endforeach; endif; ?></section><aside class="panel"><div class="panel-title"><h2>Quick links</h2></div><div class="stack"><a href="gallery.php">Browse cake inspiration →</a><a href="availability.php">Check event dates →</a><a href="profile.php">Manage account →</a></div></aside></div>
    <?php elseif ($pageId === 'gallery'): ?>
      <p class="eyebrow">Customer browse view</p><h1>Explore Our Cake Creations</h1><p class="intro">Browse past designs for inspiration. Use a design as a reference in your custom request.</p><form class="field-row" method="get"><div class="field"><label for="q">Search designs</label><input id="q" name="q" value="<?= customerEscape((string) ($_GET['q'] ?? '')) ?>" placeholder="Search by cake or occasion"></div><div class="field"><label for="occasion">Occasion</label><select id="occasion" name="occasion"><option value="">All occasions</option><?php foreach (['birthday','wedding','anniversary','graduation'] as $occasion): ?><option value="<?= $occasion ?>" <?= (($_GET['occasion'] ?? '') === $occasion) ? 'selected' : '' ?>><?= ucfirst($occasion) ?></option><?php endforeach; ?></select></div></form><section class="grid grid-4" style="margin-top:18px"><?php $visibleGallery = array_filter($gallery, static fn(array $item): bool => (($_GET['q'] ?? '') === '' || stripos($item['title'] . ' ' . $item['description'], (string) $_GET['q']) !== false) && (($_GET['occasion'] ?? '') === '' || ($item['occasion'] ?? '') === $_GET['occasion'])); ?><?php if ($visibleGallery === []): ?><p class="empty wide">No matching cake designs yet. Try another search or send your own idea.</p><?php else: foreach ($visibleGallery as $item): ?><article class="gallery-card"><img src="<?= customerEscape(preg_match('/^(?:https?:|\/)/i', $item['image_url']) ? $item['image_url'] : '../../' . $item['image_url']) ?>" alt="<?= customerEscape($item['title']) ?>" loading="lazy"><div><strong><?= customerEscape($item['title']) ?></strong><p class="muted small"><?= customerEscape(ucfirst((string) ($item['occasion'] ?? 'Custom cake'))) ?></p><a class="button-secondary" href="request.php?reference=<?= rawurlencode($item['title']) ?>">Use as reference</a></div></article><?php endforeach; endif; ?></section>
    <?php elseif ($pageId === 'availability'): ?>
      <p class="eyebrow">Plan your celebration</p><h1>Select Your Event Date &amp; Week</h1><p class="intro">Availability can change as new orders are confirmed. Select an open date to begin your request.</p><section class="panel"><div class="panel-title"><h2>Upcoming availability</h2><div class="small">🟢 Open &nbsp; 🟠 Limited &nbsp; 🔴 Fully booked</div></div><?php if ($availability === []): ?><div class="empty">Availability has not been published yet. Contact us with your preferred date.</div><?php else: ?><div class="calendar"><?php foreach ($availability as $day): $date = new DateTimeImmutable($day['availability_date']); ?><a class="day <?= customerEscape($day['availability_status']) ?>" href="request.php?date=<?= $date->format('Y-m-d') ?>"><strong><?= $date->format('j') ?></strong><span><?= $date->format('D, M j') ?><br><?= ucfirst($day['availability_status']) ?></span></a><?php endforeach; ?></div><?php endif; ?></section>
    <?php elseif ($pageId === 'request'): ?>
      <p class="breadcrumb"><a href="home.php">Home</a> / Submit Request</p><p class="eyebrow">Made to celebrate</p><h1>Custom Cake Request</h1><p class="intro">Tell us about your cake. We’ll review your details and follow up with a quotation.</p><form class="panel stack" method="post" action="../backend/requests.php" data-async data-reset-on-success="true"><input type="hidden" name="csrf_token" value="<?= customerEscape($csrf) ?>"><div class="field-row"><div class="field"><label for="request-name">Customer name *</label><input id="request-name" name="customer_name" value="<?= customerEscape($customer['full_name']) ?>" required maxlength="120"></div><div class="field"><label for="request-email">Email *</label><input id="request-email" name="email" type="email" value="<?= customerEscape($customer['email']) ?>" required maxlength="190"></div><div class="field"><label for="request-phone">Phone number *</label><input id="request-phone" name="phone" type="tel" value="<?= customerEscape($customer['phone']) ?>" required maxlength="40"></div><div class="field"><label for="event-date">Event date</label><input id="event-date" name="event_date" type="date" value="<?= customerEscape((string) ($_GET['date'] ?? '')) ?>"></div><div class="field"><label for="cake-type">Occasion</label><select id="cake-type" name="cake_type"><option value="">Choose one</option><?php foreach (['Birthday','Wedding','Anniversary','Graduation','Other'] as $option): ?><option><?= $option ?></option><?php endforeach; ?></select></div><div class="field wide"><label for="details">Design, size, flavors and notes *</label><textarea id="details" name="details" required maxlength="3000" placeholder="Describe your cake idea..."><?= customerEscape((string) ($_GET['reference'] ?? '') !== '' ? 'Reference design: ' . (string) $_GET['reference'] : '') ?></textarea></div></div><p class="message" data-message role="status" hidden></p><button class="button" type="submit">Send cake request</button></form>
    <?php elseif ($pageId === 'orders'): ?>
      <p class="eyebrow">Your account</p><h1>My Cake Orders</h1><p class="intro">View your order status, balance, and event details.</p><section class="grid grid-3" style="margin-bottom:18px"><div class="metric"><span>Total orders</span><strong><?= count($orders) ?></strong></div><div class="metric"><span>Pending / in progress</span><strong><?= count(array_filter($orders, static fn(array $order): bool => !in_array($order['order_status'], ['delivered','cancelled'], true))) ?></strong></div><div class="metric"><span>Completed</span><strong><?= count(array_filter($orders, static fn(array $order): bool => $order['order_status'] === 'delivered')) ?></strong></div></section><section class="panel"><div class="panel-title"><h2>Order list</h2><a class="button" href="request.php">New request</a></div><?php if ($orders === []): ?><div class="empty">Your orders will appear here after they are added by the bakery.</div><?php else: foreach ($orders as $order): ?><div class="order-row"><div><strong><?= customerEscape($order['cake_name']) ?></strong><p>#<?= customerEscape($order['order_number']) ?> · <?= $order['event_date'] ? date('M j, Y', strtotime($order['event_date'])) : 'Date to be confirmed' ?></p></div><?= customerStatus($order['order_status']) ?><a href="order.php?id=<?= (int) $order['order_id'] ?>">View order</a></div><?php endforeach; endif; ?></section>
    <?php elseif ($pageId === 'order'): ?>
      <p class="breadcrumb"><a href="orders.php">My Orders</a> / Order detail</p><h1><?= $myOrder ? 'Order #' . customerEscape($myOrder['order_number']) : 'Order details' ?></h1><?php if (!$myOrder): ?><div class="empty">That order could not be found in your account.</div><?php else: ?><div class="layout-main"><section class="panel stack"><div class="panel-title"><h2><?= customerEscape($myOrder['cake_name']) ?></h2><?= customerStatus($myOrder['order_status']) ?></div><p>Event date: <strong><?= $myOrder['event_date'] ? date('F j, Y', strtotime($myOrder['event_date'])) : 'To be confirmed' ?></strong></p><div class="progress"><span style="width:<?= $myOrder['order_status'] === 'delivered' ? 100 : ($myOrder['order_status'] === 'in_production' ? 58 : 18) ?>%"></span></div><p class="muted small">Order placed <?= date('M j, Y', strtotime($myOrder['created_at'])) ?></p><div class="nav-actions"><a class="button-secondary" href="fulfillment.php?id=<?= (int) $myOrder['order_id'] ?>">Fulfillment options</a><a class="button-secondary" href="cancel.php?id=<?= (int) $myOrder['order_id'] ?>">Request cancellation</a></div></section><aside class="panel"><h2>Payment summary</h2><p>Quoted amount <strong><?= moneyValue($myOrder['total_amount']) ?></strong></p><p>Paid to date <strong><?= moneyValue($myOrder['amount_paid']) ?></strong></p><p>Remaining balance <strong><?= moneyValue((float) $myOrder['total_amount'] - (float) $myOrder['amount_paid']) ?></strong></p><a href="payments.php">Payment history →</a></aside></div><?php endif; ?>
    <?php elseif ($pageId === 'payments'): ?>
      <p class="breadcrumb"><a href="orders.php">My Orders</a> / Payment History</p><h1>Payment History</h1><section class="panel table-wrap" style="margin-top:20px"><table><thead><tr><th>Date</th><th>Order</th><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th></tr></thead><tbody><?php if ($payments === []): ?><tr><td colspan="6">No payment records yet.</td></tr><?php else: foreach ($payments as $payment): ?><tr><td><?= date('Y-m-d', strtotime($payment['paid_at'])) ?></td><td><?= customerEscape($payment['order_number']) ?></td><td><?= customerEscape(ucwords(str_replace('_',' ',$payment['payment_method']))) ?></td><td><?= customerEscape($payment['reference_number'] ?? '—') ?></td><td><?= moneyValue($payment['amount']) ?></td><td><?= customerStatus($payment['payment_status']) ?></td></tr><?php endforeach; endif; ?></tbody></table></section>
    <?php elseif ($pageId === 'fulfillment'): ?>
      <?php $selectedOrder = null; foreach ($orders as $order) { if ((int) $order['order_id'] === (int) ($_GET['id'] ?? 0)) { $selectedOrder = $order; break; } } ?><p class="breadcrumb"><a href="orders.php">My Orders</a> / Fulfillment</p><p class="eyebrow">Delivery or pickup</p><h1>Fulfillment &amp; Delivery Method</h1><p class="intro">Choose how you would like to receive your order. The bakery will confirm the final details.</p><?php if (!$selectedOrder): ?><div class="empty">Choose an order from your order details to update fulfillment.</div><?php else: ?><form class="panel stack" method="post" action="../backend/portal_actions.php" data-async><input type="hidden" name="csrf_token" value="<?= customerEscape($csrf) ?>"><input type="hidden" name="action" value="fulfillment"><input type="hidden" name="order_id" value="<?= (int) $selectedOrder['order_id'] ?>"><div class="field"><label for="method">Fulfillment method</label><select id="method" name="fulfillment_method"><option value="pickup">Shop-owned pickup</option><option value="delivery">Third-party delivery</option></select></div><div class="field"><label for="address">Delivery address</label><input id="address" name="delivery_address" maxlength="500" value="<?= customerEscape((string) ($selectedOrder['delivery_address'] ?? '')) ?>"></div><div class="field-row"><div class="field"><label for="city">City</label><input id="city" name="delivery_city" maxlength="100" value="<?= customerEscape((string) ($selectedOrder['delivery_city'] ?? '')) ?>"></div><div class="field"><label for="postal">Postal code</label><input id="postal" name="delivery_postal_code" maxlength="20" value="<?= customerEscape((string) ($selectedOrder['delivery_postal_code'] ?? '')) ?>"></div></div><p class="message" data-message role="status" hidden></p><button class="button" type="submit">Save fulfillment method</button></form><?php endif; ?>
    <?php elseif ($pageId === 'feedback'): ?>
      <p class="eyebrow">Help us make it sweeter</p><h1>Customer Feedback &amp; Celebration Review</h1><p class="intro">Tell us about your experience and share a celebration photo later through your order conversation.</p><form class="panel stack" method="post" action="../backend/portal_actions.php" data-async data-reset-on-success="true"><input type="hidden" name="csrf_token" value="<?= customerEscape($csrf) ?>"><input type="hidden" name="action" value="feedback"><div class="field"><label for="order">Order</label><select id="order" name="order_id" required><option value="">Choose an order</option><?php foreach ($orders as $order): ?><option value="<?= (int) $order['order_id'] ?>"><?= customerEscape($order['order_number'] . ' · ' . $order['cake_name']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="rating">Overall rating</label><select id="rating" name="rating" required><option value="5">5 - Excellent</option><option value="4">4 - Very good</option><option value="3">3 - Good</option><option value="2">2 - Fair</option><option value="1">1 - Needs improvement</option></select></div><div class="field"><label for="review">Your review</label><textarea id="review" name="review_text" maxlength="3000" required></textarea></div><label class="small"><input type="checkbox" name="would_recommend" value="1" checked> I would recommend R&amp;R Sweet Bites</label><p class="message" data-message role="status" hidden></p><button class="button" type="submit">Submit review</button></form>
    <?php elseif ($pageId === 'quote'): ?>
      <p class="breadcrumb"><a href="orders.php">My Orders</a> / Quotation</p><p class="eyebrow">Custom cake quotation</p><h1>Your quotation</h1><section class="panel"><p>Quotation details will appear here once the bakery has prepared a quote for your request.</p><a class="button-secondary" href="orders.php">Back to my orders</a></section>
    <?php elseif ($pageId === 'cancel'): ?>
      <p class="breadcrumb"><a href="orders.php">My Orders</a> / Cancellation</p><p class="eyebrow">Order support</p><h1>Cancel Order Request</h1><p class="intro">Send a cancellation request for bakery review. Your order is not cancelled until the bakery confirms.</p><form class="panel stack" method="post" action="../backend/portal_actions.php" data-async><input type="hidden" name="csrf_token" value="<?= customerEscape($csrf) ?>"><input type="hidden" name="action" value="cancel"><div class="field"><label for="cancel-order">Order</label><select id="cancel-order" name="order_id" required><?php foreach ($orders as $order): ?><option value="<?= (int) $order['order_id'] ?>" <?= ((int) $order['order_id'] === (int) ($_GET['id'] ?? 0)) ? 'selected' : '' ?>><?= customerEscape($order['order_number'] . ' · ' . $order['cake_name']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="reason">Reason for cancellation</label><textarea id="reason" name="reason" maxlength="1000" required></textarea></div><p class="message" data-message role="status" hidden></p><button class="button" type="submit">Send cancellation request</button></form>
    <?php elseif ($pageId === 'profile'): ?>
      <p class="eyebrow">Account settings</p><h1>Manage Your Profile</h1><div class="layout-main" style="margin-top:18px"><aside class="panel"><div class="avatar" style="display:grid;place-items:center;margin-bottom:12px;width:50px;height:50px"><?= customerEscape(strtoupper(substr($customerName, 0, 1))) ?></div><strong><?= customerEscape($customer['full_name']) ?></strong><p class="muted small">Customer account</p><div class="stack"><a href="profile.php">Profile info</a><a href="payments.php">Payment history</a></div></aside><form class="panel stack" method="post" action="../backend/auth.php" data-async><input type="hidden" name="csrf_token" value="<?= customerEscape($csrf) ?>"><input type="hidden" name="action" value="update_profile"><h2>Profile Information</h2><p class="muted small">Update your name, contact number, and email.</p><div class="field"><label for="full-name">Full name</label><input id="full-name" name="full_name" value="<?= customerEscape($customer['full_name']) ?>" required maxlength="120"></div><div class="field"><label for="profile-email">Email address</label><input id="profile-email" name="email" type="email" value="<?= customerEscape($customer['email']) ?>" required maxlength="190"></div><div class="field"><label for="profile-phone">Contact number</label><input id="profile-phone" name="phone" value="<?= customerEscape($customer['phone']) ?>" required maxlength="40"></div><p class="message" data-message role="status" hidden></p><button class="button" type="submit">Save changes</button></form></div>
    <?php endif; ?>
  </main>
  <footer class="footer"><p>ABOUT US &nbsp; | &nbsp; CONTACTS</p><p>© 2019 R&amp;R Sweet Bites. All rights reserved.</p></footer>
<?php endif; ?>
<script src="assets/customer.js" defer></script>
</body>
</html>
