<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function parseIsoDate(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $year = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT);
    $month = filter_var($_GET['month'] ?? null, FILTER_VALIDATE_INT);
    if (!$year || $year < 2020 || $year > 2100 || !$month || $month < 1 || $month > 12) {
        respond(400, ['success' => false, 'error' => 'Provide a valid year and month.']);
    }

    try {
        require_once __DIR__ . '/../includes/db_connect.php';
        $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $monthEnd = $monthStart->modify('last day of this month');
        $statement = $pdo->prepare(
            'SELECT week_start_date, week_end_date, status
             FROM weekly_availability
             WHERE week_start_date <= :month_end AND week_end_date >= :month_start
             ORDER BY week_start_date'
        );
        $statement->execute([
            'month_start' => $monthStart->format('Y-m-d'),
            'month_end' => $monthEnd->format('Y-m-d'),
        ]);

        respond(200, ['success' => true, 'weeks' => $statement->fetchAll()]);
    } catch (Throwable $exception) {
        error_log('Weekly availability read error: ' . $exception->getMessage());
        respond(500, ['success' => false, 'error' => 'Unable to load weekly availability.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    respond(405, ['success' => false, 'error' => 'Method not allowed']);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$adminId = filter_var($_SESSION['admin_id'] ?? null, FILTER_VALIDATE_INT);
if (!$adminId) {
    respond(401, ['success' => false, 'error' => 'Admin login is required to change availability.']);
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';
if (!is_string($sessionToken) || !is_string($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
    respond(403, ['success' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
}

$weekStart = parseIsoDate($_POST['week_start_date'] ?? null);
$weekEnd = parseIsoDate($_POST['week_end_date'] ?? null);
$status = $_POST['status'] ?? null;
$validStatuses = ['open', 'closed', 'fully_booked'];
if (
    !$weekStart
    || !$weekEnd
    || $weekEnd->format('Y-m-d') !== $weekStart->modify('+6 days')->format('Y-m-d')
    || !is_string($status)
    || !in_array($status, $validStatuses, true)
) {
    respond(422, ['success' => false, 'error' => 'Choose a valid seven-day week and availability status.']);
}

try {
    require_once __DIR__ . '/../includes/db_connect.php';
    $statement = $pdo->prepare(
        'INSERT INTO weekly_availability (week_start_date, week_end_date, status, set_by_admin_id)
         VALUES (:week_start, :week_end, :status, :admin_id)
         ON DUPLICATE KEY UPDATE status = :updated_status, set_by_admin_id = :updated_admin_id'
    );
    $statement->execute([
        'week_start' => $weekStart->format('Y-m-d'),
        'week_end' => $weekEnd->format('Y-m-d'),
        'status' => $status,
        'admin_id' => $adminId,
        'updated_status' => $status,
        'updated_admin_id' => $adminId,
    ]);

    respond(200, [
        'success' => true,
        'week_start_date' => $weekStart->format('Y-m-d'),
        'week_end_date' => $weekEnd->format('Y-m-d'),
        'status' => $status,
        'message' => 'Availability updated.',
    ]);
} catch (Throwable $exception) {
    error_log('Weekly availability write error: ' . $exception->getMessage());
    respond(500, ['success' => false, 'error' => 'Unable to update weekly availability.']);
}
