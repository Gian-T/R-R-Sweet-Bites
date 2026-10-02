<?php
require __DIR__ . '/config.php';
header('Cache-Control: no-store');   // so the Back button never shows a stale logged-in/out page
$user = current_user();
$initials = '';
$unread = 0;
if ($user) {
    foreach (array_slice(array_values(array_filter(explode(' ', $user['full_name']))), 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$user['id']]);
    $unread = (int)$st->fetchColumn();
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>R&amp;R Sweet Bites | Custom Cakes</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&family=Platypi:wght@500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
  <style>
:root{--canvas:#1e1e1e;--cream:#fff4ef;--pink:#d9788a;--button:#db7f8e;--text:#4a2a2e;--ph:#f6cbd3;
--font-header:"Playfair Display",Georgia,"Times New Roman",serif;--font-button:"Platypi",Georgia,"Times New Roman",serif;--font-text:"Jost","Helvetica Neue",Arial,sans-serif}
*{box-sizing:border-box;margin:0}
html{scroll-behavior:smooth;scroll-padding-top:56px}
body{min-height:100vh;padding:24px;background:var(--canvas);color:var(--text);font-family:var(--font-text)}
[tabindex="-1"]:focus{outline:none}
a:focus-visible,button:focus-visible{outline:3px solid rgba(217,120,138,.6);outline-offset:2px}
img[src=""]{opacity:0}
.ph{overflow:hidden;background:var(--ph)}
.ph img{display:block;width:100%;height:100%;object-fit:cover}

/* Figma frame is 461 wide; calc(var(--u) * 1) = 1 Figma px. Text stays readable on small screens. */
.frame{width:min(100%,1200px);margin:0 auto;container-type:inline-size}
.page{--u:clamp(2.1px,calc(100cqw / 461),2.6px);background:var(--cream);border-radius:calc(var(--u) * 10);overflow:clip}

/* Nav */
.nav{position:sticky;top:0;z-index:50;display:flex;align-items:center;gap:calc(var(--u) * 20);padding:calc(var(--u) * 9) calc(var(--u) * 20) calc(var(--u) * 9) calc(var(--u) * 25);background:var(--cream)}
.brand{display:flex;align-items:center;gap:calc(var(--u) * 5);color:var(--pink);text-decoration:none}
.nav-icon{flex:none;width:calc(var(--u) * 22);height:calc(var(--u) * 22);border-radius:50%}
.brand-name{font-family:var(--font-button);font-weight:700;font-size:calc(var(--u) * 7.5);white-space:nowrap}
.menu{display:flex;flex:1;align-items:center;justify-content:space-between;gap:calc(var(--u) * 16)}
.links{display:flex;gap:calc(var(--u) * 9);padding:0;list-style:none}
.links a{display:block;padding:calc(var(--u) * 3) 0;border-bottom:1px solid transparent;color:var(--text);font-size:calc(var(--u) * 6.5);font-weight:500;text-decoration:none;white-space:nowrap}
.links a:hover{border-bottom-color:currentColor}
.links a[aria-current="page"]{border-bottom-color:var(--text)}
.auth{display:flex;gap:calc(var(--u) * 6)}
.pill{display:block;min-width:calc(var(--u) * 46);height:calc(var(--u) * 16);padding:0 calc(var(--u) * 10);border-radius:999px;background:var(--button);color:#fff;font:600 calc(var(--u) * 6.5)/calc(var(--u) * 16) var(--font-button);text-align:center;text-decoration:none;white-space:nowrap;transition:background .15s}
.pill:hover{background:#cf6c7d}
.menu-toggle{display:none;width:44px;height:44px;padding:11px;border:0;background:none;cursor:pointer}
.menu-toggle span{display:block;height:2.5px;margin:5px 0;border-radius:2px;background:var(--text);transition:transform .25s,opacity .2s}
.nav.open .menu-toggle span:nth-child(1){transform:translateY(7.5px) rotate(45deg)}
.nav.open .menu-toggle span:nth-child(2){opacity:0}
.nav.open .menu-toggle span:nth-child(3){transform:translateY(-7.5px) rotate(-45deg)}

/* Logged-in nav: bell + profile circle + dropdown */
.user-area{display:flex;align-items:center;gap:calc(var(--u) * 8)}
.bell{position:relative;width:calc(var(--u) * 13);height:calc(var(--u) * 13);padding:0;border:0;background:none;color:var(--text);cursor:pointer}
.badge{position:absolute;top:-30%;right:-45%;min-width:calc(var(--u) * 7);height:calc(var(--u) * 7);padding:0 3px;border-radius:999px;background:var(--button);color:#fff;font:700 calc(var(--u) * 4.2)/calc(var(--u) * 7) var(--font-text);text-align:center}
.profile{position:relative}
.avatar{display:grid;place-items:center;width:calc(var(--u) * 17);height:calc(var(--u) * 17);padding:0;border:0;border-radius:50%;background:#f6d6d6;color:var(--text);font:700 calc(var(--u) * 6.5) var(--font-text);cursor:pointer;transition:background .15s}
.avatar:hover,.avatar[aria-expanded="true"]{background:#f0c3c8}
.dropdown{position:absolute;top:calc(100% + 8px);right:0;z-index:60;min-width:200px;max-width:260px;overflow:hidden;border-radius:14px;background:#f8e4dd;box-shadow:0 8px 24px rgba(74,42,46,.18);font-family:var(--font-header);text-align:right}
.dropdown[hidden]{display:none}
.dropdown>*{display:block;width:100%;padding:12px 16px;border:0;border-top:1px solid var(--text);background:none;color:var(--text);font:600 15px/1.3 var(--font-header);text-align:right;text-decoration:none;cursor:pointer}
.dropdown>.dd-name{border-top:0;cursor:default;font-weight:700;overflow-wrap:anywhere}
.dropdown>a:hover,.dropdown>button:hover{background:rgba(217,120,138,.2)}

/* Log out confirmation pop-up */
.modal{position:fixed;inset:0;z-index:100;display:grid;place-items:center;padding:20px;background:rgba(30,30,30,.6)}
.modal[hidden]{display:none}
.modal-box{width:min(100%,360px);padding:26px 24px 22px;border-radius:16px;background:var(--cream);color:var(--text);text-align:center;box-shadow:0 12px 40px rgba(0,0,0,.35)}
.modal-box h2{font:600 1.2rem/1.35 var(--font-header)}
.modal-actions{display:flex;gap:12px;margin-top:22px}
.modal-actions form{flex:1;display:block}
.mbtn{display:block;width:100%;height:44px;border:1px solid var(--button);border-radius:999px;font:600 1rem var(--font-button);cursor:pointer;transition:background .15s}
.mbtn.yes{background:var(--button);color:#fff}
.mbtn.yes:hover{background:#cf6c7d}
.mbtn.no{background:transparent;color:var(--text);border-color:var(--text)}
.mbtn.no:hover{background:rgba(217,120,138,.2)}
@media (max-width:900px){
  .user-area{justify-content:flex-end;gap:16px;padding:4px 0}
  .bell{width:26px;height:26px}
  .badge{min-width:16px;height:16px;font-size:10px;line-height:16px}
  .avatar{width:44px;height:44px;font-size:15px}
}

/* Hero */
.hero{display:grid;grid-template-columns:1fr min(calc(var(--u) * 201),45%);gap:calc(var(--u) * 20);align-items:center;padding:calc(var(--u) * 6) calc(var(--u) * 23) calc(var(--u) * 34) calc(var(--u) * 24)}
.hero h1{font-family:var(--font-header);font-size:calc(var(--u) * 14.5);line-height:calc(var(--u) * 20);font-weight:500}
.hero .line{display:block;overflow:hidden}
.hero .line>span{display:block;transform:translateY(105%);animation:rise .8s cubic-bezier(.2,.7,.2,1) forwards;animation-delay:calc(var(--i)*.16s + .2s)}
.hero .pink{color:var(--pink);font-weight:600}
.hero p{max-width:calc(var(--u) * 150);margin-top:calc(var(--u) * 16);font-size:calc(var(--u) * 6.2);line-height:calc(var(--u) * 9.5);font-weight:500}
.cta{display:flex;gap:calc(var(--u) * 4);margin-top:calc(var(--u) * 15)}
.btn{display:block;width:calc(var(--u) * 94);height:calc(var(--u) * 17);border:1px solid var(--text);border-radius:999px;color:var(--text);font:600 calc(var(--u) * 6.5)/calc(var(--u) * 15) var(--font-button);text-align:center;text-decoration:none;transition:background .15s}
.btn.primary{background:var(--button)}
.btn.primary:hover{background:#cf6c7d}
.btn.ghost:hover{background:rgba(217,120,138,.2)}
.fade{opacity:0;animation:fadeup .7s ease forwards;animation-delay:calc(var(--d)*1s)}
.circle{width:100%;aspect-ratio:1;border-radius:50%;animation:pop .9s cubic-bezier(.2,.9,.3,1.2) both,float 7s ease-in-out 1.2s infinite}
@keyframes rise{to{transform:none}}
@keyframes fadeup{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes pop{from{opacity:0;transform:scale(.7)}to{opacity:1;transform:none}}
@keyframes float{50%{transform:translateY(calc(var(--u) * -6))}}

/* Features strip */
.features{background:#fff;padding:calc(var(--u) * 9) 0}
.features ul{display:grid;grid-template-columns:repeat(4,1fr);padding:0;list-style:none}
.features li{display:flex;flex-direction:column;align-items:center;gap:calc(var(--u) * 6);font-size:calc(var(--u) * 6);font-weight:600}
.ico{display:grid;place-items:center;width:calc(var(--u) * 15);height:calc(var(--u) * 15);border-radius:50%;background:var(--button);color:#fff}
.ico svg{width:calc(var(--u) * 8);height:calc(var(--u) * 8);fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* Section headings */
h2{font-family:var(--font-header);font-size:calc(var(--u) * 8);line-height:calc(var(--u) * 12);font-weight:700;text-align:center}
.eyebrow{font-family:var(--font-header);font-size:calc(var(--u) * 7.5);line-height:calc(var(--u) * 10);font-weight:600;color:var(--pink);text-align:center}

/* How it works */
.how{padding:calc(var(--u) * 22) 0 0}
.how h2{margin-top:calc(var(--u) * 3)}
.steps{display:grid;grid-template-columns:repeat(5,calc(var(--u) * 79));justify-content:center;margin-top:calc(var(--u) * 15);padding:0;list-style:none;counter-reset:s}
.steps li{counter-increment:s;display:flex;flex-direction:column;align-items:center;gap:calc(var(--u) * 5);font-size:calc(var(--u) * 6);font-weight:600;text-align:center}
.steps li::before{content:counter(s);display:grid;place-items:center;width:calc(var(--u) * 21);height:calc(var(--u) * 21);border-radius:50%;background:var(--button);color:#fff;font:700 calc(var(--u) * 9)/1 var(--font-header)}

/* Gallery */
.gallery{padding:calc(var(--u) * 26) calc(var(--u) * 32) 0}
.gallery .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:calc(var(--u) * 25);margin-top:calc(var(--u) * 21)}
.card{width:100%;aspect-ratio:81/82;border-radius:calc(var(--u) * 8)}

/* Promo band */
.promo{display:grid;gap:calc(var(--u) * 2);background:var(--cream);grid-template-columns:111fr 81fr 161fr 79fr;height:calc(var(--u) * 80);margin:calc(var(--u) * 28) calc(var(--u) * 15) 0;border-radius:calc(var(--u) * 5);overflow:hidden}
.promo-text{display:flex;flex-direction:column;align-items:flex-start;justify-content:center;padding:0 calc(var(--u) * 14) 0 calc(var(--u) * 15);background:#fbe6e2}
.promo h3{font-family:var(--font-header);font-size:calc(var(--u) * 7.5);line-height:calc(var(--u) * 10);font-weight:700}
.promo p{max-width:calc(var(--u) * 85);margin:calc(var(--u) * 6) 0 calc(var(--u) * 7);font-size:calc(var(--u) * 5.5);line-height:calc(var(--u) * 8.5);font-weight:500}
.promo .pill{min-width:0;height:calc(var(--u) * 11);padding:0 calc(var(--u) * 10);font-size:calc(var(--u) * 5.5);line-height:calc(var(--u) * 11)}
.promo .ph{height:100%;border-radius:0}

/* Reviews */
.reviews{padding:calc(var(--u) * 24) calc(var(--u) * 24) 0}
.reviews .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:calc(var(--u) * 8);margin-top:calc(var(--u) * 19)}
.review{padding:calc(var(--u) * 5) calc(var(--u) * 10);border-radius:calc(var(--u) * 7);background:#fde1e3}
.review h3{font-size:calc(var(--u) * 6.5);line-height:calc(var(--u) * 9);font-weight:700}
.review p{font-size:calc(var(--u) * 6.5);line-height:calc(var(--u) * 9);font-weight:500}

/* Footer */
.foot{margin-top:calc(var(--u) * 14);padding:calc(var(--u) * 8) 0 calc(var(--u) * 9);background:#efd0ca;font-size:calc(var(--u) * 5.5);line-height:calc(var(--u) * 8.5);font-weight:500;text-align:center}

/* Phones and tablets */
@media (max-width:900px){
  .nav{flex-wrap:wrap;gap:0;padding:8px 16px}
  .brand{margin-right:auto}
  .menu-toggle{display:block}
  .menu{display:none;flex-basis:100%;flex-direction:column;align-items:stretch;gap:10px;padding:6px 0 12px}
  .nav.open .menu{display:flex}
  .links{flex-direction:column;gap:0}
  .links a{padding:12px 4px;font-size:16px}
  .auth{gap:10px}
  .pill{flex:1;height:44px;font-size:15px;line-height:44px}
}
@media (max-width:720px){
  body{padding:0}
  .page{border-radius:0}
  .hero{grid-template-columns:1fr;gap:28px;padding:16px 24px 36px}
  .hero p{max-width:none}
  .cta{flex-wrap:wrap}
  .btn{width:auto;min-width:0;height:auto;padding:9px 20px;font-size:14px;line-height:1.3}
  .circle{width:min(78%,320px);margin:0 auto}
  .features ul{grid-template-columns:1fr 1fr;row-gap:20px}
  .features{padding:20px 0}
  .steps{grid-template-columns:repeat(auto-fit,minmax(96px,1fr));gap:20px 8px;padding:0 16px}
  .gallery{padding:44px 20px 0}
  .gallery .grid{grid-template-columns:1fr 1fr;gap:14px}
  .promo{grid-template-columns:1fr 1fr 1fr;height:auto;margin:40px 16px 0}
  .promo-text{grid-column:1/-1;padding:22px 20px}
  .promo p{max-width:none;margin:8px 0 14px}
  .promo .ph{height:34vw}
  .promo .pill{height:auto;padding:9px 20px;line-height:1.3}
  .reviews{padding:36px 16px 0}
  .reviews .cards{grid-template-columns:1fr}
  .review{padding:12px 16px}
}
@media (prefers-reduced-motion:reduce){
  html{scroll-behavior:auto}
  *,*::before,*::after{animation:none!important;transition:none!important}
  .hero .line>span{transform:none}
  .fade{opacity:1}
}
</style>
</head>
<body>
<div class="frame"><div class="page">

  <header class="nav" id="nav">
    <a class="brand" href="#home"><span class="nav-icon ph"><img src="" alt=""></span><span class="brand-name">R&amp;R Sweet Bites</span></a>
    <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="menu" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="menu" id="menu">
      <nav aria-label="Main"><ul class="links">
        <li><a href="#home" aria-current="page">Home</a></li>
        <li><a href="#gallery">Gallery</a></li>
        <li><a href="#request">Submit Request</a></li>
        <li><a href="login.php">My Order</a></li>
        <li><a href="login.php">Messages</a></li>
      </ul></nav>
      <div class="auth">
        <?php if ($user): ?>
          <div class="user-area">
            <button class="bell" type="button" aria-label="Notifications<?= $unread ? ' (' . $unread . ' unread)' : '' ?>">
              <svg viewBox="0 0 24 24" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
              <?php if ($unread > 0): ?><span class="badge"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
            </button>
            <div class="profile">
              <button class="avatar" id="avatarBtn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="profileMenu" aria-label="Account menu"><?= $h($initials) ?></button>
              <div class="dropdown" id="profileMenu" hidden>
                <div class="dd-name"><?= $h($user['full_name']) ?></div>
                <?php if ($user['role'] === 'admin'): ?><a href="admin-payment-verification.php">Admin Dashboard</a><?php endif; ?>
                <a href="#">Account Settings</a>
                <a href="#">My Reviews</a>
                <button type="button" id="logoutBtn">Log out</button>
              </div>
            </div>
          </div>
        <?php else: ?>
          <a class="pill" href="login.php">Log in</a><a class="pill" href="register.php">Sign up</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main>
    <section class="hero" id="home" tabindex="-1">
      <div>
        <h1><span class="line"><span style="--i:0">Your dream cake,</span></span><span class="line pink"><span style="--i:1">made just for you.</span></span></h1>
        <p class="fade" style="--d:.7">At R&amp;R Sweet Bites, we craft fully customized cakes for life's special moments, from design to doorstep.</p>
        <div class="cta fade" style="--d:.9">
          <a class="btn primary" href="register.php">Request a custom cake</a>
          <a class="btn ghost" href="#gallery">Browse inspirations</a>
        </div>
      </div>
      <div class="ph circle"><img src="" alt=""></div>
    </section>

    <section class="features" aria-label="Highlights"><ul>
      <li><span class="ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span>Fully customized</li><li><span class="ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></span>Clear pricing</li><li><span class="ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>Weekly scheduling</li><li><span class="ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg></span>Delivery options</li>
    </ul></section>

    <section class="how" id="how" tabindex="-1">
      <p class="eyebrow">How it works</p>
      <h2>Getting your custom cake is easy</h2>
      <ol class="steps"><li>Choose design</li><li>Submit request</li><li>Get a quote</li><li>Confirm and pay</li><li>Bake and deliver</li></ol>
    </section>

    <section class="gallery" id="gallery" tabindex="-1">
      <h2>Explore our previous creations</h2>
      <div class="grid"><div class="ph card"><img src="" alt=""></div><div class="ph card"><img src="" alt=""></div><div class="ph card"><img src="" alt=""></div><div class="ph card"><img src="" alt=""></div></div>
    </section>

    <section class="promo" id="request" tabindex="-1">
      <div class="promo-text">
        <h3>Let's make it happen!</h3>
        <p>Tell us your theme, flavor, and preferred date.</p>
        <a class="pill" href="register.php">Start your request</a>
      </div>
      <div class="ph"><img src="" alt=""></div><div class="ph"><img src="" alt=""></div><div class="ph"><img src="" alt=""></div>
    </section>

    <section class="reviews">
      <h2>What our customers say</h2>
      <div class="cards">
        <article class="review"><h3>Mark A.</h3><p>"More beautiful than I imagined."</p></article>
        <article class="review"><h3>Camille F.</h3><p>"Delivered right on time."</p></article>
        <article class="review"><h3>R. Santos</h3><p>"Design matched our theme."</p></article>
      </div>
    </section>
  </main>

  <footer class="foot"><p>ABOUT US</p><p>&copy; 2019 R&amp;R Sweet Bites. All Rights Reserved.</p></footer>

</div></div>

<div class="modal" id="logoutModal" hidden role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
  <div class="modal-box">
    <h2 id="logoutTitle">Are you sure you want to log out?</h2>
    <div class="modal-actions">
      <form method="post" action="logout.php"><button class="mbtn yes" type="submit">Yes</button></form>
      <button class="mbtn no" type="button" id="logoutNo">No</button>
    </div>
  </div>
</div>

<script>
(function () {
  "use strict";
  const avatar = document.getElementById("avatarBtn");
  if (!avatar) return;                       // not logged in
  const menu = document.getElementById("profileMenu");
  const modal = document.getElementById("logoutModal");
  const logoutBtn = document.getElementById("logoutBtn");
  const noBtn = document.getElementById("logoutNo");

  function setProfile(open) { menu.hidden = !open; avatar.setAttribute("aria-expanded", String(open)); }
  avatar.addEventListener("click", (e) => { e.stopPropagation(); setProfile(menu.hidden); });
  document.addEventListener("click", (e) => { if (!menu.contains(e.target)) setProfile(false); });
  menu.querySelectorAll('a[href="#"]').forEach((a) => a.addEventListener("click", (e) => e.preventDefault()));

  function openModal() { setProfile(false); modal.hidden = false; noBtn.focus(); }
  function closeModal() { modal.hidden = true; avatar.focus(); }
  logoutBtn.addEventListener("click", openModal);
  noBtn.addEventListener("click", closeModal);                       // No: stay logged in
  modal.addEventListener("click", (e) => { if (e.target === modal) closeModal(); });
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    if (!modal.hidden) closeModal(); else setProfile(false);
  });
})();
</script>

<script>
(function () {
  "use strict";
  const nav = document.getElementById("nav");
  const toggle = nav.querySelector(".menu-toggle");
  const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  // Responsive navigation: hamburger menu on small screens
  function setMenu(open) { nav.classList.toggle("open", open); toggle.setAttribute("aria-expanded", String(open)); }
  toggle.addEventListener("click", () => setMenu(!nav.classList.contains("open")));
  document.addEventListener("keydown", (e) => { if (e.key === "Escape") setMenu(false); });
  document.addEventListener("click", (e) => { if (!nav.contains(e.target)) setMenu(false); });
  window.matchMedia("(min-width: 901px)").addEventListener("change", () => setMenu(false));

  // Smooth scrolling for in-page links
  document.querySelectorAll('a[href^="#"]').forEach((a) => {
    a.addEventListener("click", (e) => {
      const id = a.getAttribute("href");
      const target = id.length > 1 && document.querySelector(id);
      if (!target) return;
      e.preventDefault();
      setMenu(false);
      target.scrollIntoView({ behavior: reduce ? "auto" : "smooth", block: "start" });
      history.pushState(null, "", id);
      target.focus({ preventScroll: true });
    });
  });
})();
</script>
</body>
</html>
