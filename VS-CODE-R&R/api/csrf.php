<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db_connect.php';
header('Content-Type: text/plain; charset=utf-8');

echo "session_id:                  " . session_id() . "\n";
echo "session_status:              " . session_status() . " (2 = active)\n";
echo "csrf from token():           " . substr(csrf_token(), 0, 20) . "...\n";
echo "session csrf_token:          " . ($_SESSION['csrf_token'] ?? '(not set)') . "\n";
echo "session csrf:                " . ($_SESSION['csrf']        ?? '(not set)') . "\n";
echo "session uid:                 " . ($_SESSION['uid']         ?? '(not set)') . "\n";
echo "session role:                " . ($_SESSION['role']        ?? '(not set)') . "\n";