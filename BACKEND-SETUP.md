# Cake Gallery PHP/MySQL Backend

The gallery reads records from the `gallery_item` table in the provided `rr_sweet_bites` schema. This backend currently provides the public read endpoint; adding or editing gallery items is done in phpMyAdmin or through a future admin interface.

## Setup

1. Copy the contents of `VS-CODE-R&R` into `xampp/htdocs/rr-sweet-bites/` so `Cake-Gallery.html`, `api/`, `includes/`, and `database/` are at the site root.
2. Import the provided MySQL schema file `R_Rschema_mysql.sql` in phpMyAdmin.
3. Import `database/add_gallery_occasion.sql` from this folder. It adds the occasion used by the gallery's filters.
4. Ensure at least one admin row exists, then create gallery rows whose `admin_id` refers to that admin. Use categories `cakes`, `cupcakes`, or `number-shaped`, and occasions such as `birthday`, `wedding`, or `anniversary`. Store `image_url` as a URL or a path relative to the site root.
5. Start Apache and MySQL in XAMPP and visit `http://localhost/rr-sweet-bites/Cake-Gallery.html`.

The default connection is for a local XAMPP install (`127.0.0.1`, database `rr_sweet_bites`, user `root`, blank password). Override it with `RR_DB_HOST`, `RR_DB_NAME`, `RR_DB_USER`, and `RR_DB_PASSWORD` environment variables when needed. Do not use local defaults in production.

The JSON endpoint is `api/gallery.php`; it responds to `GET` with `{ "success": true, "items": [...] }`. Serve the site through Apache/PHP, not by opening the HTML file directly.
