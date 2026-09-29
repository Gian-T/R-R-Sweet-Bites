<?php

declare(strict_types=1);

header('Location: frontend/login.php');
exit;

session_start();

if (empty($_SESSION['customer_page_csrf'])) {
    $_SESSION['customer_page_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['customer_page_csrf'];
$galleryItems = [];
$databaseAvailable = false;
$backendEndpoint = isset($isCustomerFrontend) ? '../backend/customer_requests.php' : 'backend/customer_requests.php';
$frontendScript = isset($isCustomerFrontend) ? 'customer-page.js' : 'frontend/customer-page.js';
$assetPrefix = isset($isCustomerFrontend) ? '../../' : '../';
$brandLogo = $assetPrefix . 'VS-CODE-R%26R/Images-Icons/R%26R%20Logo.jpg';

try {
  require_once __DIR__ . '/../VS-CODE-R&R/includes/db_connect.php';
  $databaseAvailable = true;
} catch (Throwable $exception) {
  error_log('Customer page database connection error: ' . $exception->getMessage());
}

if ($databaseAvailable) {
  try {
    $galleryStatement = $pdo->query(
        'SELECT image_url, title, occasion
         FROM gallery_item
         ORDER BY created_at DESC, gallery_id DESC
         LIMIT 4'
    );
    $galleryItems = $galleryStatement->fetchAll();
  } catch (Throwable $exception) {
    error_log('Customer page gallery error: ' . $exception->getMessage());
  }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function publicAssetUrl(string $path): string
{
  global $assetPrefix;

  if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/)/i', $path) === 1) {
    return $path;
  }

  return $assetPrefix . $path;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#fff7f2">
  <title>R&amp;R Sweet Bites | Custom Cakes for Your Celebrations</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      color-scheme: light;
      --ink: #382925;
      --muted: #796b67;
      --paper: #fff7f2;
      --white: #fffdfb;
      --rose: #d96f81;
      --rose-dark: #b84f63;
      --rose-pale: #f9dedb;
      --green: #617b61;
      --line: #eedbd3;
      --serif: 'Playfair Display', Georgia, serif;
      --sans: 'DM Sans', sans-serif;
    }

    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { margin: 0; background: var(--paper); color: var(--ink); font: 15px/1.6 var(--sans); }
    a { color: inherit; }
    button, input, select, textarea { font: inherit; }
    .site-header { height: 76px; display: flex; align-items: center; justify-content: space-between; gap: 24px; padding: 0 max(5vw, calc((100vw - 1240px) / 2)); background: rgba(255,253,251,.94); border-bottom: 1px solid rgba(238,219,211,.75); }
    .brand { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; color: var(--rose-dark); font: 600 19px var(--serif); white-space: nowrap; }
    .brand img { width: 42px; height: 42px; object-fit: cover; border-radius: 50%; }
    .nav-links { display: flex; align-items: center; gap: clamp(16px, 3vw, 38px); }
    .nav-links a { text-decoration: none; font-size: 13px; font-weight: 600; }
    .nav-links a:hover { color: var(--rose-dark); }
    .header-action, .button { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; border: 1px solid var(--rose); border-radius: 4px; padding: 0 19px; background: var(--rose); color: #fff; text-decoration: none; font-weight: 700; transition: background .18s ease, transform .18s ease; }
    .header-action:hover, .button:hover { background: var(--rose-dark); transform: translateY(-1px); }
    .menu-button { display: none; border: 0; background: transparent; color: var(--ink); font-size: 24px; }
    .hero { position: relative; min-height: 430px; display: grid; grid-template-columns: 1fr 1fr; align-items: center; gap: 32px; padding: 48px max(7vw, calc((100vw - 1120px) / 2)); overflow: hidden; background: radial-gradient(ellipse at 80% 42%, #f9d8d6 0 15%, transparent 15.4%), radial-gradient(ellipse at 84% 50%, #fceae4 0 26%, transparent 26.4%), linear-gradient(115deg, #fff8f2 0%, #fff2ec 56%, #f8dcd7 100%); }
    .hero-copy { position: relative; z-index: 1; max-width: 500px; animation: arrive .65s ease both; }
    .eyebrow { margin: 0 0 14px; color: var(--rose-dark); font-size: 11px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
    h1, h2, h3, p { margin-top: 0; }
    h1 { max-width: 490px; margin-bottom: 15px; font: 600 clamp(38px, 5vw, 58px)/1.08 var(--serif); }
    h1 span { color: var(--rose); }
    .hero-copy > p:not(.eyebrow) { max-width: 420px; color: var(--muted); }
    .hero-actions { display: flex; flex-wrap: wrap; gap: 11px; margin-top: 25px; }
    .button-outline { background: transparent; color: var(--rose-dark); }
    .button-outline:hover { color: white; }
    .hero-art { min-height: 310px; display: grid; place-items: center; animation: arrive .8s .1s ease both; }
    .cake-mark { width: min(330px, 75%); aspect-ratio: 1; display: grid; place-items: center; border: 1px solid rgba(217,111,129,.3); border-radius: 50%; background: repeating-linear-gradient(90deg, rgba(255,255,255,.24) 0 13px, transparent 13px 26px), #f5bfc1; box-shadow: 0 22px 50px rgba(126,68,71,.13); }
    .cake-mark img { width: 83%; height: 83%; object-fit: contain; border-radius: 50%; }
    .benefits { display: grid; grid-template-columns: repeat(4, 1fr); padding: 23px max(7vw, calc((100vw - 1120px) / 2)); background: #fffdfb; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
    .benefit { display: grid; justify-items: center; gap: 5px; padding: 5px 10px; text-align: center; }
    .benefit strong { font: 600 17px var(--serif); }
    .benefit span { color: var(--muted); font-size: 12px; }
    .section { padding: 68px max(7vw, calc((100vw - 1120px) / 2)); }
    .section-heading { margin-bottom: 30px; text-align: center; }
    .section-heading h2 { margin-bottom: 7px; font: 600 30px var(--serif); }
    .section-heading p { margin-bottom: 0; color: var(--muted); }
    .steps { display: grid; grid-template-columns: repeat(5, 1fr); gap: 18px; }
    .step { text-align: center; }
    .step-number { width: 42px; height: 42px; display: grid; place-items: center; margin: 0 auto 10px; border-radius: 50%; background: var(--rose); color: white; font-weight: 700; }
    .step h3 { margin-bottom: 4px; font: 600 16px var(--serif); }
    .step p { margin-bottom: 0; color: var(--muted); font-size: 12px; }
    .showcase { background: #fff0ed; }
    .gallery-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
    .gallery-item { overflow: hidden; border: 1px solid var(--line); border-radius: 5px; background: var(--white); animation: arrive .5s ease both; }
    .gallery-item img { display: block; width: 100%; aspect-ratio: 1.12; object-fit: cover; background: var(--rose-pale); }
    .gallery-caption { padding: 11px 13px; }
    .gallery-caption strong { display: block; font: 600 15px var(--serif); }
    .gallery-caption span { color: var(--muted); font-size: 12px; }
    .empty-gallery { grid-column: 1 / -1; padding: 30px; border: 1px dashed #d9aaa5; color: var(--muted); text-align: center; }
    .inquiry-band { display: grid; grid-template-columns: .8fr 1.2fr; gap: 38px; align-items: start; }
    .inquiry-intro h2 { font: 600 34px/1.15 var(--serif); }
    .inquiry-intro p { color: var(--muted); }
    .inquiry-form { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; padding: 24px; border: 1px solid var(--line); border-radius: 5px; background: var(--white); }
    .field { display: grid; gap: 5px; }
    .field-wide { grid-column: 1 / -1; }
    .field label { font-size: 12px; font-weight: 700; }
    .field input, .field select, .field textarea { width: 100%; min-height: 42px; padding: 9px 11px; border: 1px solid var(--line); border-radius: 3px; background: #fffaf7; color: var(--ink); }
    .field textarea { min-height: 95px; resize: vertical; }
    .field input:focus, .field select:focus, .field textarea:focus { outline: 2px solid rgba(217,111,129,.35); border-color: var(--rose); }
    .form-message { grid-column: 1 / -1; margin: 0; padding: 10px 12px; border-radius: 3px; }
    .form-error { background: #fff0ee; color: #9a3745; }
    .form-success { background: #edf4eb; color: #345b37; }
    .inquiry-form .button { width: 100%; }
    footer { display: flex; justify-content: space-between; gap: 20px; padding: 20px max(7vw, calc((100vw - 1120px) / 2)); background: #f0d2ca; color: #594844; font-size: 12px; }
    footer p { margin: 0; }
    @keyframes arrive { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    @media (max-width: 760px) {
      .site-header { height: 66px; padding: 0 20px; }
      .brand { font-size: 16px; }
      .brand img { width: 36px; height: 36px; }
      .menu-button { display: block; margin-left: auto; }
      .nav-links { position: absolute; z-index: 5; top: 66px; right: 0; left: 0; display: none; align-items: stretch; gap: 0; padding: 8px 20px 14px; background: var(--white); border-bottom: 1px solid var(--line); }
      .nav-links.open { display: grid; }
      .nav-links a { display: block; padding: 10px 0; }
      .header-action { min-height: 37px; padding: 0 12px; font-size: 12px; }
      .hero { min-height: auto; grid-template-columns: 1fr; gap: 0; padding: 52px 24px 24px; }
      .hero-art { min-height: 250px; }
      .cake-mark { width: 230px; }
      .benefits { grid-template-columns: repeat(2, 1fr); gap: 18px 8px; padding: 22px 20px; }
      .section { padding: 52px 22px; }
      .steps { grid-template-columns: repeat(2, 1fr); row-gap: 25px; }
      .step:last-child { grid-column: 1 / -1; }
      .gallery-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
      .inquiry-band { grid-template-columns: 1fr; gap: 18px; }
      footer { flex-direction: column; padding: 18px 22px; text-align: center; }
    }
    @media (max-width: 420px) {
      .nav-links a { font-size: 12px; }
      .inquiry-form { grid-template-columns: 1fr; padding: 17px; }
      .field-wide { grid-column: auto; }
      .form-message { grid-column: auto; }
    }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { scroll-behavior: auto !important; animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; } }
  </style>
</head>
<body>
  <header class="site-header">
    <a class="brand" href="#home"><img src="<?= escape($brandLogo) ?>" alt=""><span>R&amp;R Sweet Bites</span></a>
    <button class="menu-button" type="button" aria-label="Toggle navigation" aria-expanded="false">&#9776;</button>
    <nav class="nav-links" aria-label="Main navigation">
      <a href="#home">Home</a><a href="#creations">Gallery</a><a href="#how-it-works">About</a><a href="#contact">Contact</a>
    </nav>
    <a class="header-action" href="#contact">Start an order</a>
  </header>

  <main>
    <section class="hero" id="home">
      <div class="hero-copy">
        <p class="eyebrow">Made for the moments worth celebrating</p>
        <h1>Your dream cake,<br><span>made just for you.</span></h1>
        <p>From joyful birthdays to once-in-a-lifetime celebrations, we bake custom cakes with the details that make your day yours.</p>
        <div class="hero-actions"><a class="button" href="#contact">Request a custom cake</a><a class="button button-outline" href="#creations">Browse inspirations</a></div>
      </div>
      <div class="hero-art" aria-label="R&R Sweet Bites celebration cake logo"><div class="cake-mark"><img src="<?= escape($brandLogo) ?>" alt="R&amp;R Sweet Bites cake logo"></div></div>
    </section>

    <section class="benefits" aria-label="Our services">
      <div class="benefit"><strong>Made for you</strong><span>Every design is personal</span></div>
      <div class="benefit"><strong>Clear pricing</strong><span>A quote before you commit</span></div>
      <div class="benefit"><strong>Freshly baked</strong><span>Prepared for your date</span></div>
      <div class="benefit"><strong>Delivery options</strong><span>Plan pickup or delivery</span></div>
    </section>

    <section class="section" id="how-it-works">
      <div class="section-heading"><p class="eyebrow">A little cake, a lot of joy</p><h2>Getting your custom cake is easy</h2></div>
      <div class="steps">
        <article class="step"><span class="step-number">1</span><h3>Choose a design</h3><p>Find inspiration or bring your own idea.</p></article>
        <article class="step"><span class="step-number">2</span><h3>Send a request</h3><p>Tell us the date, flavors, and details.</p></article>
        <article class="step"><span class="step-number">3</span><h3>Get a quote</h3><p>We will follow up with your options.</p></article>
        <article class="step"><span class="step-number">4</span><h3>Confirm your order</h3><p>Approve the details and payment plan.</p></article>
        <article class="step"><span class="step-number">5</span><h3>Celebrate</h3><p>Pick up or receive your finished cake.</p></article>
      </div>
    </section>

    <section class="section showcase" id="creations">
      <div class="section-heading"><p class="eyebrow">A few recent favorites</p><h2>Explore our previous creations</h2><p>Use these cakes as a starting point for your own celebration.</p></div>
      <div class="gallery-grid">
        <?php if ($galleryItems === []): ?>
          <p class="empty-gallery">New cake inspiration is on its way. Browse the gallery again soon.</p>
        <?php else: ?>
          <?php foreach ($galleryItems as $item): ?>
            <article class="gallery-item">
              <img src="<?= escape(publicAssetUrl((string) $item['image_url'])) ?>" alt="<?= escape((string) $item['title']) ?>" loading="lazy">
              <div class="gallery-caption"><strong><?= escape((string) $item['title']) ?></strong><span><?= escape(ucfirst((string) ($item['occasion'] ?? 'Custom cake'))) ?></span></div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="section inquiry-band" id="contact">
      <div class="inquiry-intro"><p class="eyebrow">Your celebration starts here</p><h2>Let’s make it happen.</h2><p>Share a few details about your cake. We’ll review your request and get back to you with the next steps.</p></div>
      <form class="inquiry-form" method="post" action="<?= escape($backendEndpoint) ?>">
        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
        <p class="form-message" id="form-message" role="status" aria-live="polite" hidden></p>
        <div class="field"><label for="customer-name">Your name *</label><input id="customer-name" name="customer_name" maxlength="120" autocomplete="name" required></div>
        <div class="field"><label for="customer-email">Email address *</label><input id="customer-email" name="email" type="email" maxlength="190" autocomplete="email" required></div>
        <div class="field"><label for="customer-phone">Phone number *</label><input id="customer-phone" name="phone" type="tel" maxlength="40" autocomplete="tel" required></div>
        <div class="field"><label for="event-date">Event date</label><input id="event-date" name="event_date" type="date"></div>
        <div class="field field-wide"><label for="cake-type">Occasion</label><select id="cake-type" name="cake_type"><option value="">Choose an occasion</option><?php foreach (['Birthday', 'Wedding', 'Anniversary', 'Graduation', 'Other'] as $occasion): ?><option value="<?= escape($occasion) ?>"><?= escape($occasion) ?></option><?php endforeach; ?></select></div>
        <div class="field field-wide"><label for="cake-details">Cake details *</label><textarea id="cake-details" name="details" maxlength="3000" required placeholder="Tell us about the design, size, flavors, and anything else we should know."></textarea></div>
        <div class="field field-wide"><button class="button" type="submit">Send cake inquiry</button></div>
      </form>
    </section>
  </main>

  <footer><p>R&amp;R Sweet Bites</p><p>&copy; 2019 R&amp;R Sweet Bites. All rights reserved.</p></footer>
  <script>
    const menuButton = document.querySelector('.menu-button');
    const navigation = document.querySelector('.nav-links');
    menuButton.addEventListener('click', () => {
      const isOpen = navigation.classList.toggle('open');
      menuButton.setAttribute('aria-expanded', String(isOpen));
    });
    navigation.addEventListener('click', (event) => {
      if (event.target.closest('a')) {
        navigation.classList.remove('open');
        menuButton.setAttribute('aria-expanded', 'false');
      }
    });
  </script>
  <script src="<?= escape($frontendScript) ?>" defer></script>
</body>
</html>