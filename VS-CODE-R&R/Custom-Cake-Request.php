<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>R&R Sweet Bites - Custom Cake Request</title>
  
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Jost:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&family=Platypi:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">

  <style>
    /* CSS Variables & Theme Setup */
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

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html {
      scroll-behavior: smooth;
      height: 100%;
    }

    body {
      font-family: var(--font-text);
      background-color: var(--bg-main);
      color: var(--color-text-dark);
      line-height: 1.6;

      /* Sticky Footer Layout */
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
      overflow-x: hidden;
    }

    /* --- Navigation Bar --- */
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
      transition: all 0.3s ease;
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
    .nav-links a.active::after {
      width: 100%;
    }

    .nav-links a:hover,
    .nav-links a.active {
      color: var(--color-primary);
    }

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
      transition: transform 0.2s ease;
    }

    .user-avatar:hover {
      transform: scale(1.05);
    }

    /* Hamburger Menu Toggle */
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
      transition: all 0.3s ease;
    }

    /* --- Page Container & Breadcrumbs --- */
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
      transition: opacity 0.2s ease;
    }

    .breadcrumbs a:hover {
      opacity: 0.8;
    }

    /* --- Form Card --- */
    .form-card {
      background-color: var(--bg-card);
      border-radius: 20px;
      padding: 3rem 3.5rem;
      box-shadow: 0 10px 30px rgba(0,0,0,0.02);
    }

    .form-header {
      margin-bottom: 2.5rem;
    }

    .form-header h1 {
      font-family: var(--font-header);
      font-size: 2.5rem;
      color: var(--color-text-dark);
      margin-bottom: 0.4rem;
      font-weight: 700;
    }

    .form-header p {
      font-size: 0.95rem;
      color: var(--color-text-muted);
    }

    .form-section {
      margin-bottom: 2.5rem;
      padding-bottom: 2rem;
      border-bottom: 1px dashed var(--color-border);
    }

    .form-section:last-of-type {
      border-bottom: none;
      padding-bottom: 0;
    }

    .section-title {
      font-family: var(--font-header);
      font-size: 1.25rem;
      color: var(--color-text-dark);
      margin-bottom: 0.3rem;
      font-weight: 700;
    }

    .section-subtitle {
      font-size: 0.85rem;
      color: var(--color-text-muted);
      margin-bottom: 1.2rem;
    }

    /* --- Cake Type Toggle Grid --- */
    .cake-type-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 1rem;
    }

    .type-option {
      border: 1px solid var(--color-border);
      background-color: var(--bg-card);
      border-radius: 12px;
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
      transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
      user-select: none;
    }

    .type-option svg {
      width: 18px;
      height: 18px;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
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
      gap: 1.5rem;
      margin-bottom: 1rem;
    }

    .field-grid:last-child {
      margin-bottom: 0;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
    }

    .form-label {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--color-text-dark);
    }

    .form-label .required {
      color: var(--color-primary);
    }

    .input-control, .select-control, .textarea-control {
      width: 100%;
      padding: 0.8rem 1rem;
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
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%3C88787A' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
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

    .textarea-control {
      min-height: 110px;
      resize: vertical;
    }

    /* --- Drag & Drop Image Upload Box --- */
    .upload-box {
      border: 2px dashed #E5C3C6;
      border-radius: 12px;
      padding: 2.5rem 1rem;
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
      margin-bottom: 0.2rem;
    }

    .upload-title {
      font-weight: 600;
      font-size: 0.95rem;
      color: var(--color-text-dark);
    }

    .upload-hint {
      font-size: 0.78rem;
      color: var(--color-text-muted);
      margin-bottom: 0.5rem;
    }

    .btn-browse {
      font-family: var(--font-button);
      font-size: 1rem;
      font-weight: 600;
      padding: 0.6rem 1.5rem;
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

    /* Preview Thumbnails */
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
      animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    /* --- Submit Button --- */
    .submit-btn-wrapper {
      margin-top: 2.5rem;
    }

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

    .btn-submit:active {
      transform: translateY(0);
    }

    /* --- Modal Notification --- */
    .modal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.4);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 2000;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s ease;
    }

    .modal-overlay.active {
      opacity: 1;
      pointer-events: auto;
    }

    .modal-box {
      background: #FFFFFF;
      padding: 2.5rem 2rem;
      border-radius: 20px;
      max-width: 450px;
      width: 90%;
      text-align: center;
      box-shadow: 0 15px 35px rgba(0,0,0,0.15);
      transform: scale(0.85);
      transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .modal-overlay.active .modal-box {
      transform: scale(1);
    }

    .modal-box h3 {
      font-family: var(--font-header);
      font-size: 1.6rem;
      color: var(--color-text-dark);
      margin-bottom: 0.75rem;
    }

    .modal-box p {
      font-size: 0.95rem;
      color: var(--color-text-muted);
      margin-bottom: 1.5rem;
    }

    /* --- Footer --- */
    footer {
      background-color: var(--bg-footer);
      text-align: center;
      padding: 2rem 1rem;
      font-family: var(--font-nav);
      font-size: 0.85rem;
      color: #665557;
      flex-shrink: 0;
      width: 100%;
    }

    footer p.footer-title {
      font-weight: 700;
      letter-spacing: 1.5px;
      margin-bottom: 0.3rem;
      text-transform: uppercase;
    }

    /* --- Keyframe Animations --- */
    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes popIn {
      from { opacity: 0; transform: scale(0.6); }
      to { opacity: 1; transform: scale(1); }
    }

    /* --- Responsive Breakpoints --- */
    @media (max-width: 768px) {
      .menu-toggle {
        display: flex;
      }

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

      .nav-links.active {
        display: flex;
      }

      .form-card {
        padding: 2rem 1.5rem;
      }

      .cake-type-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .field-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 480px) {
      .cake-type-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>

  <!-- Navigation Bar -->
  <nav class="navbar">
    <a href="#home" class="nav-brand">
      <img src="Images-Icons\R&R Logo.jpg" alt="" class="brand-icon">
      <span class="brand-name">R&amp;R Sweet Bites</span>
    </a>

    <div class="menu-toggle" id="mobile-menu">
      <span></span>
      <span></span>
      <span></span>
    </div>

    <ul class="nav-links" id="nav-list">
      <li><a href="#home">Home</a></li>
      <li><a href="#gallery">Gallery</a></li>
      <li><a href="#submit-request" class="active">Submit Request</a></li>
      <li><a href="#my-order">My Order</a></li>
      <li><a href="#messages">Messages</a></li>
    </ul>

    <div class="nav-actions">
      <div class="notification-wrapper">
        <img src="Images-Icons\bell-icon.png" alt="" class="notification-icon">
        <span class="notification-badge">2</span>
      </div>
      <div class="user-avatar">AP</div>
    </div>
  </nav>

  <!-- Main Content Container -->
  <main class="main-container">
    <div class="breadcrumbs">
      <a href="#submit">Submit Request</a> / Select Event Date & Event / Custom Cake Request
    </div>

    <!-- Form Card -->
    <div class="form-card">
      <div class="form-header">
        <h1>Custom Cake Request</h1>
        <p>Tell us your vision, and we'll compute a custom quotation matching your flavor, serving scale, and intricate details.</p>
      </div>

      <form id="cakeRequestForm">
        <!-- 1. Cake Type Section -->
        <div class="form-section">
          <div class="section-title">Cake Type</div>
          <div class="section-subtitle">What's the occasion?</div>

          <div class="cake-type-grid">
            <div class="type-option selected" data-value="birthday">
              <svg viewBox="0 0 24 24"><path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v3"/><path d="M12 8v3"/><path d="M17 8v3"/><path d="M7 4h0"/><path d="M12 4h0"/><path d="M17 4h0"/></svg>
              <span>Birthday</span>
            </div>
            <div class="type-option" data-value="wedding">
              <svg viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
              <span>Wedding</span>
            </div>
            <div class="type-option" data-value="celebration">
              <svg viewBox="0 0 24 24"><path d="m2 22 1-1h3l9-9"/><path d="M3 21v-3l9-9"/><path d="m15 6 3.4-3.4a2.1 2.1 0 1 1 3 3L18 9"/></svg>
              <span>Celebration</span>
            </div>
            <div class="type-option" data-value="custom-theme">
              <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <span>Custom Theme</span>
            </div>
          </div>
        </div>

        <!-- 2. Preferred Size Section -->
        <div class="form-section">
          <div class="section-title">Preferred Size</div>
          <div class="field-grid">
            <div class="form-group">
              <label class="form-label">Tiers <span class="required">*</span></label>
              <select class="select-control" required>
                <option value="1">1 Tier</option>
                <option value="2">2 Tiers</option>
                <option value="3">3 Tiers</option>
                <option value="4+">4+ Tiers</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Layers <span class="required">*</span></label>
              <select class="select-control" required>
                <option value="3">3 Layers</option>
                <option value="2">2 Layers</option>
                <option value="4">4 Layers</option>
              </select>
            </div>
          </div>
        </div>

        <!-- 3. Flavors & Fillings Section -->
        <div class="form-section">
          <div class="section-title">Flavors &amp; Fillings</div>
          <div class="field-grid">
            <div class="form-group">
              <label class="form-label">Cake Base Flavor <span class="required">*</span></label>
              <select class="select-control" required>
                <option value="chocolate">Chocolate</option>
                <option value="vanilla">Vanilla Sponge</option>
                <option value="red-velvet">Red Velvet</option>
                <option value="uvalicious">Ube Halaya</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Internal Filling <span class="required">*</span></label>
              <select class="select-control" required>
                <option value="vanilla">Vanilla</option>
                <option value="chocolate-ganache">Chocolate Ganache</option>
                <option value="cream-cheese">Cream Cheese</option>
                <option value="salted-caramel">Salted Caramel</option>
              </select>
            </div>
          </div>
          <div class="field-grid" style="margin-top: 1rem;">
            <div class="form-group">
              <label class="form-label">Outer Frosting <span class="required">*</span></label>
              <select class="select-control" required>
                <option value="strawberry">Strawberry</option>
                <option value="buttercream">Vanilla Buttercream</option>
                <option value="swiss-meringue">Swiss Meringue</option>
                <option value="whipped-cream">Whipped Cream</option>
              </select>
            </div>
          </div>
        </div>

        <!-- 4. Decoration & Design Details Section -->
        <div class="form-section">
          <div class="section-title">Decoration &amp; Design Details &amp; Allergy Warnings</div>
          <div class="form-group">
            <textarea class="textarea-control" placeholder="Please tell us how you want your design and decoration..."></textarea>
          </div>
        </div>

        <!-- 5. Image Inspirations Section -->
        <div class="form-section">
          <div class="section-title">Image Inspirations</div>
          <div class="upload-box" id="uploadDropzone">
            <div class="upload-icon-circle">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <div class="upload-title">Upload an image</div>
            <div class="upload-hint">PNG, JPG up to 10MB (Max 3 images)</div>
            <button type="button" class="btn-browse" onclick="document.getElementById('fileInput').click()">Browse Files</button>
            <input type="file" id="fileInput" accept="image/*" multiple style="display: none;">
          </div>
          <div class="image-previews" id="previewContainer"></div>
        </div>

        <!-- 6. Delivery & Contact Info Section -->
        <div class="form-section">
          <div class="section-title">Delivery &amp; Contact Info</div>
          <div class="field-grid">
            <div class="form-group">
              <label class="form-label">Customer Name <span class="required">*</span></label>
              <input type="text" class="input-control" placeholder="Full Name" required>
            </div>
            <div class="form-group">
              <label class="form-label">Requested Delivery Date <span class="required">*</span></label>
              <input type="date" class="input-control" required>
            </div>
          </div>
          <div class="field-grid" style="margin-top: 1rem;">
            <div class="form-group">
              <label class="form-label">Email Address <span class="required">*</span></label>
              <input type="email" class="input-control" placeholder="example@gmail.com" required>
            </div>
            <div class="form-group">
              <label class="form-label">Phone Number <span class="required">*</span></label>
              <input type="tel" class="input-control" placeholder="0917 XXX XXXX" required>
            </div>
          </div>
        </div>

        <!-- Submit Button -->
        <div class="submit-btn-wrapper">
          <button type="submit" class="btn-submit">Submit Request</button>
        </div>
      </form>
    </div>
  </main>

  <!-- Modal Popup -->
  <div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
      <h3>Request Submitted!</h3>
      <p>Thank you! Your custom cake inquiry has been transmitted. We will review your selections and send a calculated quote shortly.</p>
      <button class="btn-browse" onclick="closeModal()">Awesome</button>
    </div>
  </div>

  <!-- Footer -->
  <footer>
    <p class="footer-title">ABOUT US</p>
    <p>&copy; 2019 R&amp;R Sweet Bites. All Rights Reserved.</p>
  </footer>

  <!-- Interactive JavaScript -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // 1. Mobile Navigation Toggle
      const mobileMenu = document.getElementById('mobile-menu');
      const navList = document.getElementById('nav-list');

      mobileMenu.addEventListener('click', () => {
        navList.classList.toggle('active');
      });

      // 2. Cake Type Selection Logic
      const typeOptions = document.querySelectorAll('.type-option');
      typeOptions.forEach(option => {
        option.addEventListener('click', () => {
          typeOptions.forEach(opt => opt.classList.remove('selected'));
          option.classList.add('selected');
        });
      });

      // 3. Drag & Drop File Upload Logic
      const dropzone = document.getElementById('uploadDropzone');
      const fileInput = document.getElementById('fileInput');
      const previewContainer = document.getElementById('previewContainer');

      ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
          e.preventDefault();
          dropzone.classList.add('drag-over');
        }, false);
      });

      ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
          e.preventDefault();
          dropzone.classList.remove('drag-over');
        }, false);
      });

      dropzone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        handleFiles(files);
      });

      fileInput.addEventListener('change', (e) => {
        handleFiles(e.target.files);
      });

      function handleFiles(files) {
        Array.from(files).forEach(file => {
          if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => {
              const img = document.createElement('img');
              img.src = e.target.result;
              img.classList.add('preview-thumb');
              previewContainer.appendChild(img);
            };
            reader.readAsDataURL(file);
          }
        });
      }

      // 4. Form Submission Simulation & Modal
      const cakeForm = document.getElementById('cakeRequestForm');
      const modalOverlay = document.getElementById('modalOverlay');

      cakeForm.addEventListener('submit', (e) => {
        e.preventDefault();
        modalOverlay.classList.add('active');
      });
    });

    function closeModal() {
      document.getElementById('modalOverlay').classList.remove('active');
    }
  </script>
</body>
</html>