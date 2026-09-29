<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>R&R Sweet Bites - Cake Gallery</title>
  
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
      --bg-placeholder: #F2D5D5;
      --bg-footer: #ECCFCB;
      
      --color-primary: #D27685;
      --color-primary-hover: #BE5B6C;
      --color-text-dark: #333333;
      --color-text-muted: #666666;
      --color-border: #E0E0E0;
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

      /* --- Sticky Footer Solution --- */
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
      box-shadow: 0 2px 10px rgba(0,0,0,0.03);
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
      gap: 1rem;
    }

    .notification-icon {
      width: 24px;
      height: 24px;
      object-fit: contain;
      cursor: pointer;
      transition: transform 0.2s ease;
    }

    .notification-icon:hover {
      transform: scale(1.1) rotate(10deg);
    }

    /* Standard Buttons */
    .btn {
      font-family: var(--font-button);
      font-weight: 600;
      font-size: 1rem;
      padding: 0.6rem 1.5rem;
      border-radius: 20px;
      border: 1px solid transparent;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
      display: inline-block;
      text-align: center;
    }

    .btn:active {
      transform: scale(0.96);
    }

    .btn-primary {
      background-color: var(--color-primary);
      color: #FFFFFF;
      box-shadow: 0 4px 10px rgba(210, 118, 133, 0.2);
    }

    .btn-primary:hover {
      background-color: var(--color-primary-hover);
      box-shadow: 0 6px 15px rgba(210, 118, 133, 0.35);
      transform: translateY(-2px);
    }

    .btn-outline {
      background-color: transparent;
      border-color: var(--color-primary);
      color: var(--color-primary);
    }

    .btn-outline:hover {
      background-color: var(--color-primary);
      color: #FFFFFF;
      transform: translateY(-2px);
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

    /* --- Animated Cover / Header Banner --- */
    .cover-banner {
      text-align: center;
      padding: 4rem 1rem 2rem 1rem;
      animation: fadeInDown 0.9s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .cover-banner h1 {
      font-family: var(--font-header);
      font-size: 2.5rem;
      color: #382325;
      margin-bottom: 0.5rem;
    }

    .cover-banner p {
      font-family: var(--font-text);
      font-size: 1.1rem;
      color: var(--color-text-muted);
    }

    @keyframes fadeInDown {
      from {
        opacity: 0;
        transform: translateY(-25px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* --- Gallery Container & Filters --- */
    .gallery-container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 0 1.5rem 4rem 1.5rem;
      width: 100%;
      flex: 1 0 auto;
    }

    .filter-card {
      background-color: #FFFFFF;
      padding: 1.25rem 1.5rem;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.03);
      display: flex;
      gap: 1rem;
      margin-bottom: 2.5rem;
      flex-wrap: wrap;
      animation: fadeInUp 0.8s ease-out 0.2s both;
    }

    .search-input {
      flex: 2;
      min-width: 200px;
      padding: 0.75rem 1rem;
      border: 1px solid var(--color-border);
      border-radius: 8px;
      font-family: var(--font-text);
      font-size: 0.95rem;
      outline: none;
      transition: all 0.3s ease;
    }

    .search-input:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(210, 118, 133, 0.15);
    }

    .filter-select {
      flex: 1;
      min-width: 150px;
      padding: 0.75rem 1rem;
      border: 1px solid var(--color-border);
      border-radius: 8px;
      font-family: var(--font-header);
      font-size: 0.95rem;
      color: var(--color-text-dark);
      background-color: #FFFFFF;
      outline: none;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .filter-select:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(210, 118, 133, 0.15);
    }

    /* --- Gallery Grid --- */
    .gallery-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 1.5rem;
    }

    .gallery-card {
      background-color: var(--bg-card);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 15px rgba(0,0,0,0.03);
      display: flex;
      flex-direction: column;
      opacity: 0;
      transform: translateY(30px);
      transition: transform 0.4s cubic-bezier(0.165, 0.84, 0.44, 1), 
                  box-shadow 0.4s ease, 
                  opacity 0.5s ease;
    }

    .gallery-card.visible {
      opacity: 1;
      transform: translateY(0);
    }

    .gallery-card:hover {
      transform: translateY(-8px) scale(1.01);
      box-shadow: 0 12px 30px rgba(0,0,0,0.1);
    }

    .card-image-placeholder {
      width: 100%;
      height: 220px;
      background-color: var(--bg-placeholder);
      position: relative;
      overflow: hidden;
    }

    .card-image-placeholder.has-image::after {
      display: none;
    }

    .card-image-placeholder img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .gallery-message {
      grid-column: 1 / -1;
      padding: 2rem;
      text-align: center;
      color: var(--color-text-muted);
    }

    /* Subtle shimmer animation for placeholders */
    .card-image-placeholder::after {
      content: '';
      position: absolute;
      top: 0; left: -100%;
      width: 100%; height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
      animation: shimmer 2.5s infinite;
    }

    @keyframes shimmer {
      100% { left: 100%; }
    }

    .card-info {
      padding: 1rem 1rem 0 1rem;
    }

    .card-title {
      font-family: var(--font-header);
      font-size: 1.1rem;
      color: #382325;
    }

    .card-tag {
      font-size: 0.8rem;
      color: var(--color-text-muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .card-actions {
      padding: 1rem;
      display: flex;
      gap: 0.5rem;
      justify-content: space-between;
      margin-top: auto;
    }

    .card-actions .btn {
      flex: 1;
      font-size: 0.78rem;
      padding: 0.45rem 0.2rem;
      border-radius: 6px;
      white-space: nowrap;
    }

    /* --- Modal Overlay & Dialog --- */
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
      padding: 2rem;
      border-radius: 16px;
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
      color: #382325;
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
      font-size: 0.9rem;
      color: #555555;
      flex-shrink: 0;
      width: 100%;
    }

    footer p.footer-title {
      font-weight: 600;
      letter-spacing: 1px;
      margin-bottom: 0.3rem;
      text-transform: uppercase;
    }

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

    /* --- Responsive Breakpoints --- */
    @media (max-width: 992px) {
      .gallery-grid {
        grid-template-columns: repeat(3, 1fr);
      }
    }

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

      .gallery-grid {
        grid-template-columns: repeat(2, 1fr);
      }

      .cover-banner h1 {
        font-size: 2rem;
      }
    }

    @media (max-width: 480px) {
      .gallery-grid {
        grid-template-columns: 1fr;
      }

      .filter-card {
        flex-direction: column;
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
      <li><a href="Cake-Gallery.php" class="active">Gallery</a></li>
      <li><a href="Weekly-Availability.php">Submit Request</a></li>
      <li><a href="#my-order">My Order</a></li>
      <li><a href="#messages">Messages</a></li>
    </ul>

    <div class="nav-actions">
      <img src="Images-Icons\bell-icon.png" alt="" class="notification-icon">
      <a href="#login" class="btn btn-primary">Log in</a>
      <a href="#signup" class="btn btn-primary">Sign up</a>
    </div>
  </nav>

  <!-- Animated Cover / Header Banner -->
  <header class="cover-banner" id="home">
    <h1>Explore Our Cake Creation</h1>
    <p>Explore our collection of previous custom orders for inspiration.</p>
  </header>

  <!-- Main Content Area -->
  <main class="gallery-container" id="gallery">
    <!-- Filter Controls -->
    <div class="filter-card">
      <input type="text" id="searchInput" class="search-input" placeholder="Search designs or occasions...">
      <select id="categoryFilter" class="filter-select">
        <option value="">All categories</option>
        <option value="cakes">Cakes</option>
        <option value="cupcakes">Cupcakes</option>
        <option value="number-shaped">Number/Shaped</option>
      </select>
      <select id="occasionFilter" class="filter-select">
        <option value="">All occasions</option>
        <option value="birthday">Birthday</option>
        <option value="wedding">Wedding</option>
        <option value="anniversary">Anniversary</option>
      </select>
    </div>

    <!-- Gallery Grid (12 Dynamic Cards) -->
    <div class="gallery-grid" id="galleryGrid">
      <p class="gallery-message" role="status">Loading cake designs...</p>
    </div>
  </main>

  <!-- Interactive Modal Popup -->
  <div class="modal-overlay" id="modalOverlay">
    <div class="modal-box">
      <h3 id="modalTitle">Cake Details</h3>
      <p id="modalDescription">Sample description showing details for selected design.</p>
      <button class="btn btn-primary" id="modalClose">Close</button>
    </div>
  </div>

  <!-- Footer -->
  <footer>
    <p class="footer-title">About Us</p>
    <p>&copy; 2019 R&amp;R Sweet Bites. All Rights Reserved.</p>
  </footer>

  <!-- Interactive Animations & Filter JavaScript -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const mobileMenu = document.getElementById('mobile-menu');
      const navList = document.getElementById('nav-list');
      const searchInput = document.getElementById('searchInput');
      const categoryFilter = document.getElementById('categoryFilter');
      const occasionFilter = document.getElementById('occasionFilter');
      const galleryGrid = document.getElementById('galleryGrid');
      let cards = [];

      const modalOverlay = document.getElementById('modalOverlay');
      const modalTitle = document.getElementById('modalTitle');
      const modalDescription = document.getElementById('modalDescription');
      const modalClose = document.getElementById('modalClose');

      function showMessage(message) {
        const status = document.createElement('p');
        status.className = 'gallery-message';
        status.setAttribute('role', 'status');
        status.textContent = message;
        galleryGrid.replaceChildren(status);
      }

      function makeCard(item) {
        const card = document.createElement('article');
        const category = item.category || '';
        const occasion = item.occasion || '';
        const categoryLabel = category === 'number-shaped'
          ? 'Number/Shaped'
          : `${category.charAt(0).toUpperCase()}${category.slice(1)}`;
        const occasionLabel = `${occasion.charAt(0).toUpperCase()}${occasion.slice(1)}`;
        const title = item.title || 'Untitled cake design';
        const description = item.description || 'No additional details are available.';
        card.className = 'gallery-card';
        card.dataset.category = category.toLowerCase();
        card.dataset.occasion = occasion.toLowerCase();
        card.dataset.name = `${title} ${description} ${category} ${occasion}`.toLowerCase();
        card.dataset.description = description;
        card.dataset.galleryId = item.gallery_id;

        const imageContainer = document.createElement('div');
        imageContainer.className = 'card-image-placeholder';
        if (item.image_url) {
          const image = document.createElement('img');
          image.src = item.image_url;
          image.alt = title;
          image.loading = 'lazy';
          image.addEventListener('load', () => imageContainer.classList.add('has-image'));
          imageContainer.append(image);
        }

        const info = document.createElement('div');
        info.className = 'card-info';
        const tag = document.createElement('div');
        tag.className = 'card-tag';
        tag.textContent = [categoryLabel, occasionLabel].filter(Boolean).join(' • ');
        const heading = document.createElement('h2');
        heading.className = 'card-title';
        heading.textContent = title;
        info.append(tag, heading);

        const actions = document.createElement('div');
        actions.className = 'card-actions';
        const viewButton = document.createElement('button');
        viewButton.className = 'btn btn-primary view-btn';
        viewButton.type = 'button';
        viewButton.textContent = 'View Details';
        const referenceButton = document.createElement('button');
        referenceButton.className = 'btn btn-outline ref-btn';
        referenceButton.type = 'button';
        referenceButton.textContent = 'Use as Reference';
        actions.append(viewButton, referenceButton);
        card.append(imageContainer, info, actions);
        return card;
      }

      function renderGallery(items) {
        if (!items.length) {
          showMessage('No cake designs have been added yet.');
          cards = [];
          return;
        }
        galleryGrid.replaceChildren(...items.map(makeCard));
        cards = galleryGrid.querySelectorAll('.gallery-card');
        cards.forEach(card => cardObserver.observe(card));
        filterGallery();
      }

      function filterGallery() {
        const query = searchInput.value.toLowerCase().trim();
        const category = categoryFilter.value;
        const occasion = occasionFilter.value;

        cards.forEach(card => {
          const matchesQuery = card.dataset.name.includes(query);
          const matchesCategory = !category || card.dataset.category === category;
          const matchesOccasion = !occasion || card.dataset.occasion === occasion;
          card.style.display = matchesQuery && matchesCategory && matchesOccasion ? 'flex' : 'none';
        });
      }

      // 1. Mobile Menu Toggle
      mobileMenu.addEventListener('click', () => {
        navList.classList.toggle('active');
      });

      document.querySelectorAll('.nav-links a').forEach(link => {
        link.addEventListener('click', () => {
          navList.classList.remove('active');
        });
      });

      // 2. Scroll Animation Observer for Cards
      const observerOptions = {
        threshold: 0.1,
        rootMargin: "0px 0px -50px 0px"
      };

      const cardObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
          if (entry.isIntersecting) {
            // Stagger reveal slightly for each visible card
            setTimeout(() => {
              entry.target.classList.add('visible');
            }, index * 80);
            cardObserver.unobserve(entry.target);
          }
        });
      }, observerOptions);
      fetch('api/gallery.php')
        .then(response => {
          if (!response.ok) throw new Error('Gallery request failed');
          return response.json();
        })
        .then(result => {
          if (!result.success || !Array.isArray(result.items)) throw new Error('Invalid gallery response');
          renderGallery(result.items);
        })
        .catch(() => showMessage('Cake designs could not be loaded. Please try again later.'));

      searchInput.addEventListener('input', filterGallery);
      categoryFilter.addEventListener('change', filterGallery);
      occasionFilter.addEventListener('change', filterGallery);

      // 4. Interactive Modals for Action Buttons
      galleryGrid.addEventListener('click', event => {
        const card = event.target.closest('.gallery-card');
        if (!card) return;
        const title = card.querySelector('.card-title').textContent;

        if (event.target.closest('.view-btn')) {
          modalTitle.textContent = title;
          modalDescription.textContent = card.dataset.description;
          modalOverlay.classList.add('active');
        } else if (event.target.closest('.ref-btn')) {
          modalTitle.textContent = 'Reference Selected';
          modalDescription.textContent = `"${title}" is selected as your design reference.`;
          modalOverlay.classList.add('active');
        }
      });

      modalClose.addEventListener('click', () => {
        modalOverlay.classList.remove('active');
      });

      modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) {
          modalOverlay.classList.remove('active');
        }
      });
    });
  </script>
</body>
</html>