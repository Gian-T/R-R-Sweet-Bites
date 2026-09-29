<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function authResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function authCsrfToken(): string
{
    if (empty($_SESSION['customer_csrf'])) {
        $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['customer_csrf'];
}

$action = (string) ($_POST['action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    authResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($postedToken) || !hash_equals(authCsrfToken(), $postedToken)) {
    authResponse(['success' => false, 'message' => 'Your session expired. Refresh and try again.'], 400);
}

if ($action === 'logout') {
    $_SESSION = [];
    session_regenerate_id(true);
    authCsrfToken();
    authResponse(['success' => true, 'redirect' => 'login.php']);
}

try {
    require_once __DIR__ . '/../../VS-CODE-R&R/includes/db_connect.php';

    if ($action === 'register') {
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirm'] ?? '');

        if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190 || $phone === '' || mb_strlen($phone) > 40 || strlen($password) < 8 || $password !== $confirmation) {
            authResponse(['success' => false, 'message' => 'Check the form: use a valid email, phone number, matching passwords, and a password with at least 8 characters.'], 422);
        }

        $statement = $pdo->prepare(
            'INSERT INTO customer_portal_customers (full_name, email, phone, password_hash)
             VALUES (:full_name, :email, :phone, :password_hash)'
        );
        try {
            $statement->execute([
                'full_name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                authResponse(['success' => false, 'message' => 'An account with that email already exists.'], 409);
            }
            throw $exception;
        }

        $_SESSION['customer_id'] = (int) $pdo->lastInsertId();
        $_SESSION['customer_name'] = $name;
        $_SESSION['customer_email'] = $email;
        session_regenerate_id(true);
        authResponse(['success' => true, 'redirect' => 'home.php']);
    }

    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $pdo->prepare(
            'SELECT customer_id, full_name, email, password_hash, is_active
             FROM customer_portal_customers WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $customer = $statement->fetch();

        if (!$customer || !(bool) $customer['is_active'] || !password_verify($password, $customer['password_hash'])) {
            authResponse(['success' => false, 'message' => 'Email or password was not recognized.'], 401);
        }

        session_regenerate_id(true);
        $_SESSION['customer_id'] = (int) $customer['customer_id'];
        $_SESSION['customer_name'] = $customer['full_name'];
        $_SESSION['customer_email'] = $customer['email'];
        authResponse(['success' => true, 'redirect' => 'home.php']);
    }

    if ($action === 'update_profile') {
        if (empty($_SESSION['customer_id'])) {
            authResponse(['success' => false, 'message' => 'Please sign in again.'], 401);
        }
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($name === '' || mb_strlen($name) > 120 || $phone === '' || mb_strlen($phone) > 40 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            authResponse(['success' => false, 'message' => 'Enter a valid name, email address, and phone number.'], 422);
        }
        $statement = $pdo->prepare('UPDATE customer_portal_customers SET full_name = :full_name, email = :email, phone = :phone WHERE customer_id = :customer_id');
        try {
            $statement->execute(['full_name' => $name, 'email' => $email, 'phone' => $phone, 'customer_id' => $_SESSION['customer_id']]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                authResponse(['success' => false, 'message' => 'That email address is already used by another account.'], 409);
            }
            throw $exception;
        }
        $_SESSION['customer_name'] = $name;
        $_SESSION['customer_email'] = $email;
        authResponse(['success' => true, 'message' => 'Your profile has been updated.']);
    }

    authResponse(['success' => false, 'message' => 'Unknown account action.'], 400);
} catch (Throwable $exception) {
    error_log('Customer auth error: ' . $exception->getMessage());
    authResponse(['success' => false, 'message' => 'The account service is unavailable. Please try again shortly.'], 503);
}
