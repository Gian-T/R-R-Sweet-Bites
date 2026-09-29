<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function requestResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    requestResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['customer_csrf']) || !is_string($token) || !hash_equals($_SESSION['customer_csrf'], $token)) {
    requestResponse(['success' => false, 'message' => 'Your session expired. Refresh and try again.'], 400);
}

$name = trim((string) ($_POST['customer_name'] ?? $_SESSION['customer_name'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? $_SESSION['customer_email'] ?? '')));
$phone = trim((string) ($_POST['phone'] ?? ''));
$date = trim((string) ($_POST['event_date'] ?? ''));
$type = trim((string) ($_POST['cake_type'] ?? ''));
$details = trim((string) ($_POST['details'] ?? ''));

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || $details === '') {
    requestResponse(['success' => false, 'message' => 'Enter your name, valid email, phone number, and cake details.'], 422);
}
if (mb_strlen($name) > 120 || mb_strlen($email) > 190 || mb_strlen($phone) > 40 || mb_strlen($type) > 80 || mb_strlen($details) > 3000) {
    requestResponse(['success' => false, 'message' => 'Some details are too long. Please shorten them.'], 422);
}
if ($date !== '') {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if ($parsed === false || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $parsed->format('Y-m-d') !== $date) {
        requestResponse(['success' => false, 'message' => 'Choose a valid event date.'], 422);
    }
}

try {
    require_once __DIR__ . '/../../VS-CODE-R&R/includes/db_connect.php';
    $statement = $pdo->prepare(
        'INSERT INTO customer_cake_requests (customer_name, email, phone, event_date, cake_type, details)
         VALUES (:customer_name, :email, :phone, :event_date, :cake_type, :details)'
    );
    $statement->execute([
        'customer_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'event_date' => $date !== '' ? $date : null,
        'cake_type' => $type !== '' ? $type : null,
        'details' => $details,
    ]);
    requestResponse(['success' => true, 'message' => 'Your cake request was sent. We will follow up with your quote.']);
} catch (Throwable $exception) {
    error_log('Customer request error: ' . $exception->getMessage());
    requestResponse(['success' => false, 'message' => 'We could not save your request. Check that you imported the customer SQL.'], 503);
}
