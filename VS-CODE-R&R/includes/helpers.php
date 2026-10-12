<?php
declare(strict_types=1);

/**
 * Shared helpers for the JSON endpoints (login, register, etc.).
 * Required by login.php and register.php.
 */

/** Read the JSON request body as an associative array. Returns [] on garbage input. */
function json_input(): array
{
    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Emit a JSON response and stop. */
function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** True if $ip is in the comma-separated list of IPs / CIDR ranges. */
function ip_in_list(string $ip, array $list): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) return false;
    foreach ($list as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $nbin = @inet_pton($net);
        if ($nbin === false || strlen($nbin) !== strlen($bin)) continue;
        $bits  = $bits === null ? strlen($bin) * 8 : max(0, min((int)$bits, strlen($bin) * 8));
        $bytes = intdiv($bits, 8);
        if ($bytes && substr($bin, 0, $bytes) !== substr($nbin, 0, $bytes)) continue;
        $rest = $bits % 8;
        if ($rest) {
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) !== (ord($nbin[$bytes]) & $mask)) continue;
        }
        return true;
    }
    return false;
}

/**
 * Is the direct connection from a proxy/CDN we trust?
 * Set RR_TRUSTED_PROXIES (comma-separated IPs/CIDRs) ONLY if the site really sits behind
 * one (Cloudflare, load balancer...). Left empty, forwarded headers are ignored.
 */
function from_trusted_proxy(): bool
{
    $list = array_filter(array_map('trim', explode(',', (string)(getenv('RR_TRUSTED_PROXIES') ?: ''))));
    return $list && ip_in_list($_SERVER['REMOTE_ADDR'] ?? '', $list);
}

/** Visitor IP. Honors CF-Connecting-IP / X-Forwarded-For only from a trusted proxy. */
function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (from_trusted_proxy()) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string)$_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    return $remote;
}

/** True when the visitor reached us over HTTPS (also behind a trusted proxy). */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    return from_trusted_proxy()
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/* ---------- Field validators (return null if valid, or an error message) ---------- */

function v_name(string $name): ?string
{
    if ($name === '')                 return 'Enter your full name.';
    if (mb_strlen($name) < 3)         return 'Name is too short.';
    if (mb_strlen($name) > 60)        return 'Keep your name under 60 characters.';
    if (count(preg_split('/\s+/', trim($name))) < 2) return 'Enter your first and last name.';
    return null;
}

function v_email(string $email): ?string
{
    if ($email === '')             return 'Email is required.';
    if (strlen($email) > 254)      return 'Email is too long.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Enter a valid email.';
    return null;
}

function v_contact(string $contact): ?string
{
    $d = preg_replace('/\D/', '', $contact);
    if ($d === '')              return 'Enter your contact number.';
    if (!str_starts_with($d, '09')) return 'Number must start with 09.';
    if (strlen($d) !== 11)      return 'Number must be 11 digits.';
    return null;
}

function v_password(string $password): ?string
{
    if ($password === '')          return 'Create a password.';
    if (strlen($password) < 8)     return 'Use at least 8 characters.';
    if (strlen($password) > 64)    return 'Use 64 characters or fewer.';
    if (!preg_match('/[A-Z]/', $password)) return 'Add one capital letter.';
    if (!preg_match('/\d/', $password))    return 'Add one number.';
    return null;
}

/* ---------- Session helpers ---------- */

/** Return the currently logged-in user (from customer or admin), or null. */
function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;

    if (($_SESSION['role'] ?? '') === 'admin') {
        $st = db()->prepare('SELECT admin_id AS id, "admin" AS role, full_name, email FROM admin WHERE admin_id = ?');
        $st->execute([$_SESSION['uid']]);
    } else {
        $st = db()->prepare('SELECT customer_id AS id, "customer" AS role, full_name, email FROM customer WHERE customer_id = ?');
        $st->execute([$_SESSION['uid']]);
    }
    return $st->fetch() ?: null;
}

function require_customer(): int
{
    if (($_SESSION['role'] ?? '') !== 'customer' || (int) ($_SESSION['uid'] ?? 0) <= 0) {
        json_out(['ok' => false, 'message' => 'Please log in.', 'error' => 'Please log in.'], 401);
    }
    return (int) $_SESSION['uid'];
}

/** Logged-in admin, or 401. */
function require_admin(): array
{
    if (($_SESSION['role'] ?? '') !== 'admin' || (int) ($_SESSION['uid'] ?? 0) <= 0) {
        json_out(['ok' => false, 'message' => 'Admin only.', 'error' => 'Admin only.'], 401);
    }
    return ['admin_id' => (int) $_SESSION['uid']];
}