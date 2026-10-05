<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$me = current_user();
$defaultName  = '';
$defaultEmail = '';
$defaultPhone = '';

if ($me && $me['role'] === 'customer') {
    $st = db()->prepare('SELECT full_name, email, contact_number FROM customer WHERE customer_id = ?');
    $st->execute([$me['id']]);
    if ($row = $st->fetch()) {
        $defaultName  = $row['full_name'];
        $defaultEmail = $row['email'];
        $defaultPhone = $row['contact_number'];
    }
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>R&amp;R Sweet Bites - Custom Cake Request</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Jost:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&family=Platypi:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">

  <style>
    :root {
      --font-nav: 'Jost', sans-serif;
      --font-brand: 'Platypi', serif;
      --font-header: 'Playfair Display', serif;
      --font-button: 'Platypi', serif;
      --font-text: 'Jost', sans-serif;

      --bg-main: #FAF0E6;
      --bg-card: #FFFFFF;
      --bg-input: #F8F8F8;
      --bg-footer: #ECCFCB;

      --color-primary: #D27685;
      --color-primary-hover: #BE5B6C;
      --color-primary-light: #FDF5F6;
      --color-text-dark: #382325;
      --color-text-muted: #88787A;
      --color-border: #EAE2E2;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    html { scroll-behavior: smooth; height: 100%; }

    body {
      font-family: var(--font-text);
      background-color: var(--bg-main);
      color: var(--color-text-dark);
      line-height: 1.6;
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
      overflow-x: hidden;
    }

    /* --- Navigation --- */
    .navbar {
      position: sticky;
      top: 0;
      z-index: 1000;
      background-color: #FAF0E6;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1rem 5%;
      box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    }

    .nav-brand {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
    }

    .brand-icon {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background-color: #E8C3C3;
      object-fit: cover;
    }

    .brand-name {
      font-family: var(--font-brand);
      font-weight: 700;
      font-size: 1.35rem;
      color: var(--color-primary);
    }

    .nav-links {
      display: flex;
      list-style: none;
      align-items: center;
      gap: 2rem;
      font-family: var(--font-nav);
    }

    .nav-links a {
      text-decoration: none;
      color: var(--color-text-dark);
      font-weight: 500;
      font-size: 1rem;
      transition: color 0.3s ease;
      position: relative;
    }

    .nav-links a::after {
      content: '';
      position: absolute;
      width: 0%;
      height: 2px;
      bottom: -4px;
      left: 0;
      background-color: var(--color-primary);
      transition: width 0.3s ease;
    }

    .nav-links a:hover::after,
    .nav-links a.active::after { width: 100%; }

    .nav-links a:hover,
    .nav-links a.active { color: var(--color-primary); }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 1.2rem;
    }

    .notification-wrapper {
      position: relative;
      cursor: pointer;
      display: flex;
      align-items: center;
    }

    .notification-icon {
      width: 24px;
      height: 24px;
      object-fit: contain;
      transition: transform 0.2s ease;
    }

    .notification-wrapper:hover .notification-icon {
      transform: scale(1.1) rotate(10deg);
    }

    .notification-badge {
      position: absolute;
      top: -5px;
      right: -6px;
      background-color: var(--color-primary);
      color: white;
      font-size: 0.65rem;
      font-weight: 700;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .user-avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background-color: #E8CFCD;
      color: #5C3D42;
      font-family: var(--font-nav);
      font-weight: 600;
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }

    .menu-toggle {
      display: none;
      flex-direction: column;
      cursor: pointer;
      gap: 5px;
    }

    .menu-toggle span {
      width: 25px;
      height: 3px;
      background-color: var(--color-primary);
      border-radius: 2px;
    }

    /* --- Page Container --- */
    .main-container {
      max-width: 900px;
      margin: 0 auto;
      padding: 1.5rem 1.5rem 4rem 1.5rem;
      width: 100%;
      flex: 1 0 auto;
      animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .breadcrumbs {
      font-size: 0.85rem;
      color: var(--color-text-muted);
      margin-bottom: 1.5rem;
    }

    .breadcrumbs a {
      color: var(--color-primary);
      text-decoration: none;
    }

    /* --- Form Card --- */
    .form-card {
      background-color: var(--bg-card);
      border-radius: 20px;
      padding: 2.5rem 3rem;
      box-shadow: 0 10px 30px rgba(0,0,0,0.02);
    }

    .form-header {
      margin-bottom: 2rem;
      padding-bottom: 1.5rem;
      border-bottom: 1px solid var(--color-border);
    }

    .form-header h1 {
      font-family: var(--font-header);
      font-size: 2.3rem;
      color: var(--color-text-dark);
      margin-bottom: 0.6rem;
      font-weight: 700;
    }

    .form-header-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 1.5rem;
      flex-wrap: wrap;
    }

    .selected-date-block {
      display: flex;
      flex-direction: column;
      gap: 0.3rem;
    }

    .selected-date-label {
      font-size: 0.8rem;
      color: var(--color-text-muted);
      font-weight: 500;
    }

    .selected-date-value {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      background: #FFF9F5;
      border: 1px solid var(--color-border);
      border-radius: 8px;
      padding: 0.5rem 0.9rem;
      font-size: 0.95rem;
      font-weight: 600;
      color: var(--color-text-dark);
    }

    .selected-date-value svg {
      width: 16px;
      height: 16px;
      color: var(--color-primary);
    }

    /* --- Rush Row (at top of form) --- */
    .rush-row {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
      background: #FDF5E4;
      border: 1px solid #F0DEB0;
      border-radius: 12px;
      padding: 0.9rem 1.2rem;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
    }

    .rush-toggle {
      display: inline-flex;
      align-items: center;
      gap: 0.7rem;
      font-size: 0.95rem;
      font-weight: 600;
      cursor: pointer;
      user-select: none;
      white-space: nowrap;
    }

    .rush-toggle input { display: none; }

    .rush-switch {
      width: 40px;
      height: 22px;
      border-radius: 12px;
      background: #E8E2E0;
      position: relative;
      transition: background 0.2s;
      flex-shrink: 0;
    }

    .rush-switch::after {
      content: '';
      position: absolute;
      top: 3px;
      left: 3px;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background: #FFFFFF;
      transition: transform 0.2s;
      box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    }

    .rush-toggle input:checked + .rush-switch { background: var(--color-primary); }
    .rush-toggle input:checked + .rush-switch::after { transform: translateX(18px); }

    .rush-notice {
      display: flex;
      gap: 0.5rem;
      align-items: flex-start;
      font-size: 0.8rem;
      color: #8A6A1E;
      line-height: 1.5;
      flex: 1;
      min-width: 200px;
    }

    .rush-notice svg {
      width: 14px;
      height: 14px;
      flex-shrink: 0;
      margin-top: 3px;
    }

    /* --- Form Sections --- */
    .form-section {
      padding: 1.8rem 0;
      border-bottom: 1px solid var(--color-border);
    }

    .form-section:last-of-type { border-bottom: none; padding-bottom: 0; }

    .section-title {
      font-family: var(--font-header);
      font-size: 1.2rem;
      color: var(--color-text-dark);
      margin-bottom: 0.3rem;
      font-weight: 700;
    }

    .section-subtitle {
      font-size: 0.82rem;
      color: var(--color-text-muted);
      margin-bottom: 1.2rem;
    }

    /* --- Occasion Grid --- */
    .occasion-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 0.8rem;
    }

    .type-option {
      border: 1px solid var(--color-border);
      background-color: var(--bg-card);
      border-radius: 10px;
      padding: 0.9rem 0.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      cursor: pointer;
      font-family: var(--font-text);
      font-size: 0.88rem;
      font-weight: 600;
      color: var(--color-text-dark);
      transition: all 0.25s ease;
      user-select: none;
    }

    .type-option svg {
      width: 18px;
      height: 18px;
      fill: none;
      stroke: currentColor;
      stroke-width: 1.8;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .type-option:hover {
      border-color: var(--color-primary);
      transform: translateY(-2px);
    }

    .type-option.selected {
      border-color: var(--color-primary);
      background-color: var(--color-primary-light);
      color: var(--color-primary-hover);
      box-shadow: 0 4px 12px rgba(210, 118, 133, 0.12);
    }

    /* --- Form Field Controls --- */
    .field-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.2rem;
      margin-bottom: 1rem;
    }

    .field-grid:last-child { margin-bottom: 0; }

    .form-group { display: flex; flex-direction: column; gap: 0.4rem; }

    .form-label {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--color-text-dark);
    }

    .form-label .required { color: var(--color-primary); }

    .input-control, .select-control, .textarea-control {
      width: 100%;
      padding: 0.75rem 1rem;
      background-color: var(--bg-input);
      border: 1px solid transparent;
      border-radius: 10px;
      font-family: var(--font-text);
      font-size: 0.9rem;
      color: var(--color-text-dark);
      outline: none;
      transition: all 0.3s ease;
    }

    .select-control {
      appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2388787A' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 1rem center;
      padding-right: 2.5rem;
      cursor: pointer;
    }

    .input-control:focus, .select-control:focus, .textarea-control:focus {
      background-color: #FFFFFF;
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(210, 118, 133, 0.15);
    }

    .input-control[readonly] {
      background-color: #F2EFED;
      color: #6A585A;
      cursor: default;
    }

    .textarea-control {
      min-height: 100px;
      resize: vertical;
    }

    /* --- Upload Box --- */
    .upload-box {
      border: 2px dashed #E5C3C6;
      border-radius: 12px;
      padding: 2rem 1rem;
      text-align: center;
      background-color: #FAF6F6;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.6rem;
      transition: all 0.3s ease;
      cursor: pointer;
    }

    .upload-box:hover, .upload-box.drag-over {
      background-color: #F8EDED;
      border-color: var(--color-primary);
    }

    .upload-icon-circle {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      background-color: #F3DEDF;
      color: var(--color-primary);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .upload-title {
      font-weight: 600;
      font-size: 0.95rem;
      color: var(--color-text-dark);
    }

    .upload-hint {
      font-size: 0.78rem;
      color: var(--color-text-muted);
    }

    .btn-browse {
      font-family: var(--font-button);
      font-size: 0.9rem;
      font-weight: 600;
      padding: 0.5rem 1.3rem;
      border-radius: 20px;
      border: 1px solid var(--color-primary);
      background-color: #FFFFFF;
      color: var(--color-primary);
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .btn-browse:hover {
      background-color: var(--color-primary);
      color: #FFFFFF;
    }

    .image-previews {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
      margin-top: 1rem;
    }

    .preview-thumb {
      width: 70px;
      height: 70px;
      border-radius: 8px;
      object-fit: cover;
      border: 1px solid var(--color-border);
    }

    /* --- Submit Button --- */
    .submit-btn-wrapper { margin-top: 2rem; }

    .btn-submit {
      width: 100%;
      font-family: var(--font-button);
      font-weight: 600;
      font-size: 1rem;
      padding: 1rem;
      background-color: var(--color-primary);
      color: #FFFFFF;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      box-shadow: 0 4px 15px rgba(210, 118, 133, 0.3);
      transition: all 0.3s ease;
    }

    .btn-submit:hover {
      background-color: var(--color-primary-hover);
      box-shadow: 0 6px 20px rgba(210, 118, 133, 0.4);
      transform: translateY(-2px);
    }

    .btn-submit:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    #requestStatus {
      text-align: center;
      color: #C65F62;
      font-size: 0.9rem;
      margin-top: 0.8rem;
      min-height: 1.2em;
    }

    /* --- Modal --- */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.45);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 2000;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s ease;
      padding: 1rem;
    }

    .modal-overlay.active { opacity: 1; pointer-events: auto; }

    .modal-box {
      background: #FFFFFF;
      padding: 2rem 2rem 1.5rem;
      border-radius: 20px;
      max-width: 420px;
      width: 100%;
      text-align: center;
      box-shadow: 0 15px 35px rgba(0,0,0,0.15);
      transform: scale(0.9);
      transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .modal-overlay.active .modal-box { transform: scale(1); }

    .success-check {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: #F3DEDF;
      color: var(--color-primary);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1rem;
    }

    .success-check svg { width: 28px; height: 28px; }

    .modal-box h3 {
      font-family: var(--font-header);
      font-size: 1.4rem;
      color: var(--color-text-dark);
      margin-bottom: 0.5rem;
    }

    .modal-box > p {
      font-size: 0.88rem;
      color: var(--color-text-muted);
      margin-bottom: 1.2rem;
      line-height: 1.5;
    }

    .modal-summary {
      background: #FAF5F0;
      border-radius: 10px;
      padding: 0.9rem 1rem;
      margin-bottom: 1rem;
      text-align: left;
      font-size: 0.82rem;
    }

    .modal-summary .row {
      display: flex;
      justify-content: space-between;
      padding: 0.35rem 0;
    }

    .modal-summary .row .k { color: var(--color-text-muted); font-weight: 500; }
    .modal-summary .row .v { color: var(--color-text-dark); font-weight: 700; }

    .order-summary-card {
      background: #F6D8D6;
      border-radius: 10px;
      padding: 0.9rem 1rem;
      margin-bottom: 1rem;
      text-align: left;
      font-size: 0.82rem;
      color: #7A3B45;
    }

    .order-summary-card .row { padding: 0.25rem 0; }

    .modal-note {
      font-size: 0.75rem;
      color: var(--color-text-muted);
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
    }

    .modal-note svg { width: 12px; height: 12px; }

    .modal-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.7rem;
    }

    .btn-modal-primary {
      padding: 0.7rem;
      background: var(--color-primary);
      color: #FFFFFF;
      border: none;
      border-radius: 10px;
      font-family: var(--font-button);
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      text-decoration: none;
      display: block;
      text-align: center;
      transition: background 0.2s;
    }

    .btn-modal-primary:hover { background: var(--color-primary-hover); }

    .btn-modal-outline {
      padding: 0.7rem;
      background: #FFFFFF;
      color: var(--color-primary);
      border: 1px solid var(--color-primary);
      border-radius: 10px;
      font-family: var(--font-button);
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      transition: background 0.2s;
    }

    .btn-modal-outline:hover { background: var(--color-primary-light); }

    /* --- Footer --- */
    footer {
      background-color: var(--bg-footer);
      text-align: center;
      padding: 1.8rem 1rem;
      font-family: var(--font-nav);
      font-size: 0.85rem;
      color: #665557;
    }

    footer p.footer-title {
      font-weight: 700;
      letter-spacing: 1.5px;
      margin-bottom: 0.3rem;
      text-transform: uppercase;
    }

    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* --- Responsive --- */
    @media (max-width: 768px) {
      .menu-toggle { display: flex; }

      .nav-links {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background-color: #FAF0E6;
        flex-direction: column;
        padding: 1.5rem;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
      }

      .nav-links.active { display: flex; }

      .form-card { padding: 2rem 1.5rem; }

      .occasion-grid { grid-template-columns: repeat(2, 1fr); }

      .field-grid { grid-template-columns: 1fr; }

      .form-header-row { flex-direction: column; }
    }

    @media (max-width: 480px) {
      .occasion-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

  <!-- Navigation -->
  <nav class="navbar">
    <a href="#home" class="nav-brand">
      <img src="../Images-Icons/R&R Logo.jpg" alt="" class="brand-icon">
      <span class="brand-name">R&amp;R Sweet Bites</span>
    </a>

    <div class="menu-toggle" id="mobile-menu">
      <span></span><span></span><span></span>
    </div>

    <ul class="nav-links" id="nav-list">
      <li><a href="#home">Home</a></li>
      <li><a href="Cake-Gallery.html">Gallery</a></li>
      <li><a href="Weekly-Availability.html" class="active">Submit Request</a></li>
      <li><a href="Customer_Orders.html">My Order</a></li>
      <li><a href="#messages">Messages</a></li>
    </ul>

    <div class="nav-actions">
      <div class="notification-wrapper">
        <img src="../Images-Icons/bell-icon.png" alt="" class="notification-icon">
        <span class="notification-badge">2</span>
      </div>
      <div class="user-avatar">AP</div>
    </div>
  </nav>

  <!-- Main -->
  <main class="main-container">
    <div class="breadcrumbs">
      Submit Request / Select Event Date &amp; Event / Custom Cake Request
    </div>

    <div class="form-card">
      <div class="form-header">
        <div class="form-header-row">
          <div>
            <h1>Custom Cake Request</h1>
          </div>
          <div class="selected-date-block">
            <span class="selected-date-label">Selected Date:</span>
            <span class="selected-date-value" id="selectedDateDisplay">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <span id="selectedDateText">—</span>
            </span>
          </div>
        </div>
      </div>

      <form id="cakeRequestForm" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>

        <!-- Rush Order toggle (at top) -->
        <div class="rush-row">
          <label class="rush-toggle">
            <input type="checkbox" name="is_rush" value="1">
            <span class="rush-switch"></span>
            <span>Rush Order</span>
          </label>
          <p class="rush-notice">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>Rush orders are subject to approval based on the cake's complexity and our availability. A rush fee will be applied.</span>
          </p>
        </div>

        <!-- 1. Cake Type -->
        <div class="form-section">
          <div class="section-title">Cake Type</div>
          <div class="section-subtitle">Choose the product for your request.</div>

          <div class="occasion-grid">
            <div class="type-option selected" data-value="cake">
              <svg viewBox="0 0 24 24"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/></svg>
              <span>Cake</span>
            </div>
            <div class="type-option" data-value="cupcake">
              <svg viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
              <span>Cupcakes</span>
            </div>
            <div class="type-option" data-value="number_shaped_cake">
              <svg viewBox="0 0 24 24"><path d="m2 22 1-1h3l9-9"/><path d="M3 21v-3l9-9"/><path d="m15 6 3.4-3.4a2.1 2.1 0 1 1 3 3L18 9"/></svg>
              <span>Number/Shaped</span>
            </div>
          </div>
          <input type="hidden" name="cake_type" id="cakeType" value="cake">
        </div>

        <!-- 2. Preferred Size -->
        <div class="form-section">
          <div class="section-title">Preferred Size</div>
          <div class="section-subtitle">Choose the size that suits your event.</div>

          <div class="field-grid">
            <div class="form-group">
              <label class="form-label">Tiers <span class="required">*</span></label>
              <select class="select-control" name="num_tiers" required>
                <option value="1">1 Tier</option>
                <option value="2">2 Tiers</option>
                <option value="3">3 Tiers</option>
                <option value="4">4+ Tiers</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Layers <span class="required">*</span></label>
              <select class="select-control" name="num_layers" required>
                <option value="2">2 Layers</option>
                <option value="3" selected>3 Layers</option>
                <option value="4">4 Layers</option>
              </select>
            </div>
          </div>

          <div class="field-grid">
            <div class="form-group">
              <label class="form-label">Cake Height <span class="required">*</span></label>
              <select class="select-control" name="cake_height" required>
                <option value="">Select Height</option>
                <option value="4in">4 inches</option>
                <option value="5in">5 inches</option>
                <option value="6in">6 inches</option>
                <option value="7in">7 inches</option>
                <option value="8in">8 inches</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Cake Width <span class="required">*</span></label>
              <select class="select-control" name="cake_width" required>
                <option value="">Select Width</option>
                <option value="4in">4 inches</option>
                <option value="6in">6 inches</option>
                <option value="8in">8 inches</option>
                <option value="10in">10 inches</option>
                <option value="12in">12 inches</option>
              </select>
            </div>
          </div>
        </div>

        <!-- 3. Flavors & Fillings -->
        <div class="form-section">
          <div class="section-title">Cake Base, Flavors &amp; Fillings</div>

          <div class="field-grid">
            <div class="form-group">
              <label class="form-label">Cake Base <span class="required">*</span></label>
              <select class="select-control" name="flavor" required>
                <option value="">Select Cake Base</option>
                <option value="chocolate">Chocolate</option>
                <option value="vanilla">Vanilla Sponge</option>
                <option value="red-velvet">Red Velvet</option>
                <option value="ube">Ube Halaya</option>
                <option value="others">Others</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Cake Base Flavor <span class="required">*</span></label>
              <select class="select-control" name="flavor_variant" required>
                <option value="">Select Flavor</option>
                <option value="classic">Classic</option>
                <option value="premium">Premium</option>
                <option value="sugar-free">Sugar Free</option>
                <option value="others">Others</option>
              </select>
            </div>
          </div>

          <div class="field-grid">
            <div class="form-group">
              <label class="form-label">Outer Frosting <span class="required">*</span></label>
              <select class="select-control" name="frosting" required>
                <option value="">Select Frosting</option>
                <option value="buttercream">Vanilla Buttercream</option>
                <option value="swiss-meringue">Swiss Meringue</option>
                <option value="whipped-cream">Whipped Cream</option>
                <option value="strawberry">Strawberry</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Internal Filling <span class="required">*</span></label>
              <select class="select-control" name="filling" required>
                <option value="">Select Filling</option>
                <option value="vanilla">Vanilla</option>
                <option value="chocolate-ganache">Chocolate Ganache</option>
                <option value="cream-cheese">Cream Cheese</option>
                <option value="salted-caramel">Salted Caramel</option>
              </select>
            </div>
          </div>

          <div class="field-grid" style="grid-template-columns: 1fr;">
            <div class="form-group">
              <label class="form-label">Cake Base Flavor <span class="required">*</span></label>
              <input type="text" class="input-control" name="flavor_custom" placeholder="Tell us your preferred flavor..." maxlength="150">
            </div>
          </div>
        </div>

        <!-- 4. Decoration & Design Details -->
        <div class="form-section">
          <div class="section-title">Decoration &amp; Design Details</div>
          <div class="form-group">
            <label class="form-label" for="designDescription">Design details <span class="required">*</span></label>
            <textarea class="textarea-control" id="designDescription" name="design_description" maxlength="10000" placeholder="Please tell us how you want your design and decoration..." required></textarea>
          </div>
        </div>

        <!-- 5. Image Inspirations -->
        <div class="form-section">
          <div class="section-title">Image Inspirations</div>
          <div class="upload-box" id="uploadDropzone">
            <div class="upload-icon-circle">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <div class="upload-title">Upload an image</div>
            <div class="upload-hint">PNG, JPG up to 10MB (Max 3 images)</div>
            <button type="button" class="btn-browse" onclick="document.getElementById('fileInput').click()">Browse Files</button>
            <input type="file" id="fileInput" name="reference_images[]" accept="image/jpeg,image/png,image/webp" multiple style="display: none;">
          </div>
          <div class="image-previews" id="previewContainer"></div>
        </div>

        <!-- 6. Contact Information -->
        <div class="form-section">
          <div class="section-title">Contact Information</div>
          <div class="field-grid">
            <div class="form-group">
              <label class="form-label">Full Name <span class="required">*</span></label>
              <input type="text" class="input-control" name="customer_name"
                     value="<?= $h($defaultName) ?>" readonly>
            </div>
            <div class="form-group">
              <label class="form-label">Email Address <span class="required">*</span></label>
              <input type="email" class="input-control" name="email"
                     value="<?= $h($defaultEmail) ?>" readonly>
            </div>
          </div>
          <div class="field-grid">
            <div class="form-group">
              <label class="form-label">Phone Number <span class="required">*</span></label>
              <input type="tel" class="input-control" name="phone"
                     value="<?= $h($defaultPhone) ?>" readonly>
            </div>
            <div class="form-group">
              <label class="form-label">Requested Delivery Date <span class="required">*</span></label>
              <input type="date" class="input-control" name="preferred_date" id="preferredDateInput" min="<?= date('Y-m-d') ?>" required>
            </div>
          </div>
        </div>

        <!-- Hidden: intricacy rating (kept for the PHP) -->
        <input type="hidden" name="design_intricacy_rating" value="3">

        <div class="submit-btn-wrapper">
          <button type="submit" class="btn-submit">Submit Request</button>
          <p id="requestStatus" role="alert" aria-live="polite"></p>
        </div>
      </form>
    </div>
  </main>

  <!-- Success Modal -->
  <div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
      <div class="success-check">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
      </div>
      <h3>Request submitted</h3>
      <p>Thanks for sharing your vision. We'll review your request and send your quotation within 24 hours.</p>

      <div class="modal-summary">
        <div class="row">
          <span class="k">Reference no.</span>
          <span class="v" id="modalRef">—</span>
        </div>
        <div class="row">
          <span class="k">Status</span>
          <span class="v" style="color: #B98733;">Pending Review</span>
        </div>
      </div>

      <div class="order-summary-card">
        <div class="row"><strong>Order summary</strong></div>
        <div class="row" id="modalCakeType">—</div>
        <div class="row" id="modalSize">—</div>
        <div class="row" id="modalFlavor">—</div>
      </div>

      <p class="modal-note">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        You'll be notified once your quotation is ready.
      </p>

      <div class="modal-actions">
        <a href="Customer_Orders.html" class="btn-modal-primary">View my order</a>
        <button type="button" class="btn-modal-outline" onclick="closeModal()">Back to home</button>
      </div>
    </div>
  </div>

  <footer>
    <p class="footer-title">ABOUT US &nbsp;|&nbsp; CONTACTS</p>
    <p>&copy; 2019 R&amp;R Sweet Bites. All Rights Reserved.</p>
  </footer>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      /* ---------- Mobile nav ---------- */
      const mobileMenu = document.getElementById('mobile-menu');
      const navList = document.getElementById('nav-list');
      mobileMenu.addEventListener('click', () => navList.classList.toggle('active'));

      /* ---------- Cake type toggle ---------- */
      const typeOptions = document.querySelectorAll('.type-option');
      const cakeTypeInput = document.getElementById('cakeType');
      typeOptions.forEach(option => {
        option.addEventListener('click', () => {
          typeOptions.forEach(opt => opt.classList.remove('selected'));
          option.classList.add('selected');
          cakeTypeInput.value = option.dataset.value;
        });
      });

      /* ---------- File upload ---------- */
      const dropzone = document.getElementById('uploadDropzone');
      const fileInput = document.getElementById('fileInput');
      const previewContainer = document.getElementById('previewContainer');
      const MAX_FILES = 3;

      ['dragenter', 'dragover'].forEach(ev =>
        dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.add('drag-over'); })
      );
      ['dragleave', 'drop'].forEach(ev =>
        dropzone.addEventListener(ev, e => { e.preventDefault(); dropzone.classList.remove('drag-over'); })
      );
      dropzone.addEventListener('drop', e => handleFiles(e.dataTransfer.files));
      fileInput.addEventListener('change', e => handleFiles(e.target.files));

      function handleFiles(files) {
        const list = Array.from(files).slice(0, MAX_FILES);
        list.forEach(file => {
          if (!file.type.startsWith('image/')) return;
          const reader = new FileReader();
          reader.onload = ev => {
            const img = document.createElement('img');
            img.src = ev.target.result;
            img.classList.add('preview-thumb');
            previewContainer.appendChild(img);
          };
          reader.readAsDataURL(file);
        });
      }

      /* ---------- Preferred date from URL ---------- */
      const params = new URLSearchParams(window.location.search);
      const requestedDate = params.get('preferred_date');
      const preferredDateInput = document.getElementById('preferredDateInput');
      const selectedDateText = document.getElementById('selectedDateText');
      const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

      let resolvedDate;
      if (requestedDate && /^\d{4}-\d{2}-\d{2}$/.test(requestedDate)) {
        resolvedDate = requestedDate;
      } else {
        resolvedDate = new Date().toISOString().slice(0, 10);
      }

      preferredDateInput.value = resolvedDate;
      const [y, m, d] = resolvedDate.split('-');
      selectedDateText.textContent = `${parseInt(d, 10)} ${monthNames[parseInt(m, 10) - 1]} ${y}`;

      /* ---------- Submit ---------- */
      const cakeForm = document.getElementById('cakeRequestForm');
      const modalOverlay = document.getElementById('modalOverlay');
      const requestStatus = document.getElementById('requestStatus');
      const submitButton = cakeForm.querySelector('[type="submit"]');

      cakeForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        requestStatus.textContent = '';
        submitButton.disabled = true;

        try {
          const response = await fetch('../api/custom-cake-request.php', {
            method: 'POST',
            body: new FormData(cakeForm)
          });
          const text = await response.text();
          let result;
          try {
            result = JSON.parse(text);
          } catch (parseErr) {
            throw new Error('Server did not return JSON: ' + text.slice(0, 120));
          }

          if (!response.ok || !result.success) {
            throw new Error(result.error || 'Unable to submit your request.');
          }

          // Populate modal
          document.getElementById('modalRef').textContent = 'ORD-' + String(result.order_id).padStart(5, '0');

          const cakeTypeEl = document.querySelector('.type-option.selected span');
          document.getElementById('modalCakeType').textContent = cakeTypeEl ? cakeTypeEl.textContent : 'Cake';

          const tiers = cakeForm.elements['num_tiers'].value;
          const layers = cakeForm.elements['num_layers'].value;
          const height = cakeForm.elements['cake_height'].value || '—';
          const width = cakeForm.elements['cake_width'].value || '—';
          document.getElementById('modalSize').textContent =
            `${tiers} tier${tiers === '1' ? '' : 's'} · ${layers} layers · ${height} × ${width}`;

          const base = cakeForm.elements['flavor'].value || '—';
          const filling = cakeForm.elements['filling'].value || '—';
          const frosting = cakeForm.elements['frosting'].value || '—';
          document.getElementById('modalFlavor').textContent =
            `Base: ${base}; Filling: ${filling}; Frosting: ${frosting}`;

          modalOverlay.classList.add('active');
        } catch (error) {
          requestStatus.textContent = error.message || 'Unable to submit your request. Please try again.';
        } finally {
          submitButton.disabled = false;
        }
      });
    });

    function closeModal() {
      document.getElementById('modalOverlay').classList.remove('active');
    }
  </script>
</body>
</html>