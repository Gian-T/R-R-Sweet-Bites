<?php
declare(strict_types=1);

// Minimal .env loader (no extra library needed)
$envFile = __DIR__ . '/../.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\"'");
    }
}

$env = fn(string $k, string $d = '') => $_ENV[$k] ?? getenv($k) ?: $d;

return [
    'host'      => $env('MAIL_HOST', 'smtp.gmail.com'),
    'port'      => (int)$env('MAIL_PORT', '587'),
    'secure'    => $env('MAIL_SECURE', 'tls'),      // 'tls' = port 587, 'ssl' = port 465
    'username'  => $env('MAIL_USERNAME'),
    'password'  => $env('MAIL_PASSWORD'),
    'from'      => $env('MAIL_FROM', $env('MAIL_USERNAME')),
    'from_name' => $env('MAIL_FROM_NAME', 'R&R Sweet Bites'),
    'reply_to'  => $env('MAIL_REPLY_TO'),
];