<?php
declare(strict_types=1);

/**
 * includes/env.php — loads <project root>/.env once, early.
 *
 * Real server environment variables always win over the file.
 * Values go into $_ENV and getenv(), so db_connect.php, helpers.php and
 * admin_verification.php can all read them with getenv() (same file mail_config.php reads).
 */
function load_env(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $file = __DIR__ . '/../.env';
    if (!is_readable($file)) return;

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\"'");
        if ($k === '' || getenv($k) !== false) continue;   // real env var wins
        $_ENV[$k] = $v;
        putenv("{$k}={$v}");
    }
}