<?php
require __DIR__ . '/../includes/db_connect.php';
header('Content-Type: text/plain');
echo "env():     ", env('APP_URL'), "\n";
echo "\$_ENV:     ", var_export($_ENV['APP_URL'] ?? null, true), "\n";
echo "\$_SERVER:  ", var_export($_SERVER['APP_URL'] ?? null, true), "\n";
echo "getenv():  ", var_export(getenv('APP_URL'), true), "\n";
echo "secret key set:     ", env('PAYMONGO_SECRET_KEY') !== '' ? 'yes' : 'no', "\n";
echo "webhook secret set: ", env('PAYMONGO_WEBHOOK_SECRET') !== '' ? 'yes' : 'no', "\n";