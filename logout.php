<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: index.php'); exit; }

$_SESSION = [];
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
header('Location: index.php');
