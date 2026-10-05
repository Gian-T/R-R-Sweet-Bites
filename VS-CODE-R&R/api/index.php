<?php
declare(strict_types=1);
 
/**
 * R&R Sweet Bites — Landing page (server-rendered)
 *
 * Reads the current session to decide whether to show:
 *   - the logged-out navbar (Log in / Sign up pills), or
 *   - the logged-in navbar (bell + avatar + dropdown + logout modal)
 *
 * Works with the project's schema:
 *   customer(customer_id, full_name, email, contact_number, password_hash, ...)
 *   admin(admin_id, full_name, email, password_hash, ...)
 *   notification(notification_id, order_id, recipient_type, recipient_id, message, is_read, created_at)
 */
 
require_once __DIR__ . '../includes/db_connect.php';
require_once __DIR__ . '../includes/helpers.php';
 
header('Cache-Control: no-store');   // so the Back button never shows a stale logged-in/out page
 
$user = current_user();
$initials = '';
$unread = 0;
 
if ($user) {
    // Initials: first letter of first and last word of full_name
    foreach (array_slice(array_values(array_filter(explode(' ', $user['full_name']))), 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
 
    // Unread notification count — uses the real `notification` table
    try {
        $st = db()->prepare(
            'SELECT COUNT(*) FROM notification
              WHERE recipient_type = ? AND recipient_id = ? AND is_read = 0'
        );
        $st->execute([$user['role'], $user['id']]);
        $unread = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('index.php: notification count failed: ' . $e->getMessage());
        $unread = 0;
    }
}
 
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
 
/* ---- Image paths: edit the filenames here to match your folders ---- */
$logo = '..\Images-Icons\R&R Logo.jpg';
$fallback = '..\Cakes-Images\Happy-Birthday-70age.jpg';   // shown if an image below is missing
$gallery = [
    ['..\Cakes-Images\Happy-Birthday-70age.jpg', 'Birthday cake with pink rosettes'],
    ['..\Cakes-Images\black-gold-cake.jpg',      'Dark green cake with gold spheres'],
    ['..\Cakes-Images\pastel-rainbow-cake.jpg',  'Pastel rainbow cake with butterflies'],
    ['..\Cakes-Images\cupcakes.jpg',             'Box of frosted cupcakes'],
];
$promo = [
    ['..\Cakes-Images\castle-cake.jpg',   'Pink castle cake'],
    ['..\Cakes-Images\name-cake.jpg',     'Cake with custom name toppers'],
    ['..\Cakes-Images\rainbow-cupcakes.jpg', 'Rainbow themed cupcakes'],
];
$onerr = "this.onerror=null;this.src='" . $fallback . "'";
?>