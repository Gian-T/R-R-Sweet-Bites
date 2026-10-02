<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// One-time setup page. Works only on your own computer, and only while no admin exists yet.
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$local) { http_response_code(404); exit; }

$adminExists = (bool)db()->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetch();
$message = ''; $error = ''; $name = ''; $email = '';

if (!$adminExists && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $name  = trim((string)preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');
    $error = v_name($name) ?? v_email($email) ?? v_password($pass) ?? '';
    if (!$error) {
        try {
            db()->prepare('INSERT INTO users (role, full_name, email, contact, password_hash) VALUES ("admin", ?, ?, "09000000001", ?)')
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            $message = 'Admin account created. Now DELETE this file (create-admin.php), then log in.';
            $adminExists = true;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            $error = 'That email is already registered.';
        }
    }
}
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create admin | R&amp;R Sweet Bites</title>
<style>
  body{font-family:Jost,Arial,sans-serif;background:#fff4ef;color:#4a2a2e;display:grid;place-items:center;min-height:100vh;margin:0}
  main{background:#fff;border-radius:14px;padding:28px;width:min(92vw,380px);box-shadow:0 4px 18px rgba(0,0,0,.08)}
  h1{font-size:1.3rem;margin:0 0 14px} label{display:block;margin:12px 0 4px;font-weight:600;font-size:.9rem}
  input{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #e6b0bb;border-radius:9px;font:inherit}
  button{margin-top:18px;width:100%;padding:11px;border:0;border-radius:999px;background:#db7f8e;color:#fff;font:600 1rem inherit;cursor:pointer}
  .ok{background:#e6f4ea;color:#1e6b3a;padding:10px;border-radius:8px}.err{background:#fde8ec;color:#b3263e;padding:10px;border-radius:8px}
</style></head><body><main>
  <h1>Create the admin account</h1>
  <?php if ($message): ?><p class="ok"><?= $h($message) ?></p>
  <?php elseif ($adminExists): ?><p class="ok">An admin already exists. Please delete this file.</p>
  <?php else: ?>
    <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>
    <form method="post">
      <label for="name">Full name</label><input id="name" name="name" value="<?= $h($name) ?>" required>
      <label for="email">Email</label><input id="email" name="email" type="email" value="<?= $h($email) ?>" required>
      <label for="password">Password (8+ characters, 1 capital, 1 number)</label><input id="password" name="password" type="password" required>
      <button type="submit">Create admin</button>
    </form>
  <?php endif; ?>
</main></body></html>
