# R&R Sweet Bites - Cake Gallery Website

## What this does
A dynamic website for R&R Sweet Bites bakery where customers can browse
cake designs, search by occasion/style, and view details. Cake data is
stored in a MySQL database and managed through PHP.

## Tech Stack
- Frontend: HTML, CSS, JavaScript
- Backend: PHP
- Database: MySQL (via phpMyAdmin)
- Local server: XAMPP (Apache)

## How to run it locally
1. Install XAMPP (https://www.apachefriends.org/)
2. Copy this project folder into: xampp/htdocs/rr-sweet-bites/
3. Start Apache and MySQL in the XAMPP Control Panel
4. Open phpMyAdmin (http://localhost/phpmyadmin)
5. Import the database: import `database/rr_sweetbites.sql`
6. Visit: http://localhost/rr-sweet-bites/index.php

## Database setup
- Database name: `rr_sweetbites`
- Main table: `cakes` (id, name, price, category, description, image_path)
- Connection settings: `includes/db_connect.php`

## Folder structure
htdocs/rr-sweet-bites/
├── index.php
├── gallery.php
├── includes/ (db_connect.php, header.php, footer.php)
├── css/
├── js/
├── images/
└── database/ (SQL export file)

## Known issues / To-do
- (be honest here, e.g. "Search only works client-side, not tied to DB yet")

## Team
- [Your names] — [role, e.g. "front-end", "database", "PHP logic"]