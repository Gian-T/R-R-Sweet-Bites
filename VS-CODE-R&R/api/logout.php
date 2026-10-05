<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';

// POST only
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../public/login.html');
    exit;
}

// Capture the role BEFORE destroying the session
$wasAdmin = ($_SESSION['role'] ?? '') === 'admin';

// Clear session data
$_SESSION = [];

// Expire the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 3600,
            'path'     => $params['path'] ?: '/',
            'domain'   => $params['domain'] ?? '',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]
    );
}

session_destroy();

// Route based on the role we captured earlier
header('Location: ' . ($wasAdmin ? '../public/admin-login.html' : '../public/login.html'));
exit;