<?php
declare(strict_types=1);

/**
 * api/availability_get.php — returns weeks + per-day active flags for one month,
 * plus the current admin's identity for the topbar.
 * Contract:  GET ?month=YYYY-MM
 * Response:  {success:true, weeks:[...], me:{full_name, initials}|null}
 *            {success:false, error:"..."} on failure
 */

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/* ---------- Admin session required ---------- */
if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['admin_id'])) {
    fail('Admin login required.', 401);
}
$adminId = (int) $_SESSION['admin_id'];

/* ---------- Admin identity (for the topbar) ---------- */
$adminName = '';
$adminInitials = '';
try {
    $ast = db()->prepare('SELECT full_name FROM admin WHERE admin_id = ?');
    $ast->execute([$adminId]);
    $adminName = trim((string)($ast->fetchColumn() ?: ''));
    if ($adminName !== '') {
        $words = array_slice(array_values(array_filter(preg_split('/\s+/', $adminName))), 0, 2);
        $adminInitials = mb_strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), $words)));
    }
} catch (Throwable $e) {
    error_log('availability_get.php identity: ' . $e->getMessage());
}

/* ---------- Input ---------- */
$month = trim((string)($_GET['month'] ?? ''));
if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
    fail('Provide a month in YYYY-MM format.');
}
$year = (int)$m[1];
$mon  = (int)$m[2];
if ($year < 2020 || $year > 2100 || $mon < 1 || $mon > 12) {
    fail('Provide a valid year and month.');
}

/* ---------- Range for the month ---------- */
$monthStart = sprintf('%04d-%02d-01', $year, $mon);
$monthEnd   = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');

try {
    $pdo = db();

    /* Weeks that overlap the month */
    $st = $pdo->prepare(
        "SELECT week_id, week_start_date, week_end_date, status,
                max_capacity, booked_count, note
           FROM weekly_availability
          WHERE week_start_date <= :month_end AND week_end_date >= :month_start
          ORDER BY week_start_date ASC"
    );
    $st->execute(['month_start' => $monthStart, 'month_end' => $monthEnd]);
    $weeks = $st->fetchAll();

    /* Per-day flags for those weeks */
    $weekIds = array_map(fn($w) => (int)$w['week_id'], $weeks);
    $dayMap = [];
    if ($weekIds) {
        $in = implode(',', array_fill(0, count($weekIds), '?'));
        $d = $pdo->prepare(
            "SELECT week_id, day_date, is_active
               FROM weekly_availability_day
              WHERE week_id IN ($in)"
        );
        $d->execute($weekIds);
        foreach ($d->fetchAll() as $row) {
            $dayMap[(int)$row['week_id']][$row['day_date']] = (int)$row['is_active'];
        }
    }

    /* Shape the response for the calendar UI */
    $statusLabel = [
        'open'         => 'Open',
        'fully_booked' => 'Full',
        'closed'       => 'Closed',
    ];

    $out = [];
    foreach ($weeks as $w) {
        $wid   = (int)$w['week_id'];
        $start = $w['week_start_date'];
        $week  = [];

        for ($i = 0; $i < 7; $i++) {
            $d = (new DateTimeImmutable($start))->modify("+$i days")->format('Y-m-d');
            $week[] = $dayMap[$wid][$d] ?? 1;
        }

        $out[] = [
            'week_id' => $wid,
            'start'   => $start,
            'end'     => $w['week_end_date'],
            'active'  => $week,
            'status'  => $statusLabel[$w['status']] ?? 'Open',
            'cap'     => (int)$w['max_capacity'],
            'booked'  => (int)$w['booked_count'],
            'note'    => (string)($w['note'] ?? ''),
        ];
    }

    echo json_encode([
        'success' => true,
        'weeks'   => $out,
        'me'      => $adminName !== ''
            ? ['full_name' => $adminName, 'initials' => $adminInitials ?: 'A']
            : null,
    ]);
} catch (Throwable $e) {
    error_log('availability_get.php: ' . $e->getMessage());
    fail('Unable to load weekly availability.', 500);
}