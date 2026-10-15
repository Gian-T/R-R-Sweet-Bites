<?php
declare(strict_types=1);

/**
 * api/availability_save.php — upsert a month's weeks (from the calendar UI).
 * Contract:  POST weeks=<json>
 * Body shape: [{week_id?, start, end, active:[7], status, cap, booked, note}]
 * Response:  {success:true, message:"..."} | {success:false, error:"..."}
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

/* ---------- Admin + CSRF required ---------- */
if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['admin_id'])) {
    fail('Admin login required.', 401);
}
if (!csrf_ok()) {
    fail('Invalid or missing CSRF token.', 403);
}

$adminId = (int)$_SESSION['admin_id'];

/* ---------- Input ---------- */
$raw = (string)($_POST['weeks'] ?? '');
if ($raw === '') fail('Missing weeks payload.');

$decoded = json_decode($raw, true);
if (!is_array($decoded)) fail('Weeks payload is not valid JSON.');

$validStatuses = ['open', 'closed', 'fully_booked'];
$labelToEnum = [
    'Open'   => 'open',
    'Closed' => 'closed',
    'Full'   => 'fully_booked',
];

$pdo = db();
$pdo->beginTransaction();

try {
    $upsertWeek = $pdo->prepare(
        "INSERT INTO weekly_availability
            (week_start_date, week_end_date, status, max_capacity, booked_count, note, set_by_admin_id)
         VALUES (:start, :end, :status, :cap, :booked, :note, :admin)
         ON DUPLICATE KEY UPDATE
            status        = VALUES(status),
            max_capacity  = VALUES(max_capacity),
            booked_count  = VALUES(booked_count),
            note          = VALUES(note),
            set_by_admin_id = VALUES(set_by_admin_id)"
    );

    $findWeek = $pdo->prepare(
        "SELECT week_id FROM weekly_availability
          WHERE week_start_date = ? AND week_end_date = ?"
    );

    $delDays = $pdo->prepare("DELETE FROM weekly_availability_day WHERE week_id = ?");
    $insDay  = $pdo->prepare(
        "INSERT INTO weekly_availability_day (week_id, day_date, is_active)
         VALUES (?, ?, ?)"
    );

    // Derive booked_count from confirmed orders in the same week window.
    $bookedStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM `order`
          WHERE status IN ('confirmed','downpayment_pending_verification','in_production','out_for_delivery','ready_for_pickup','completed')
            AND preferred_date BETWEEN ? AND ?"
    );

    $saved = 0;

    foreach ($decoded as $w) {
        $start  = (string)($w['start']  ?? '');
        $end    = (string)($w['end']    ?? '');
        $active = is_array($w['active'] ?? null) ? $w['active'] : [];
        $label  = (string)($w['status'] ?? 'Open');
        $cap    = max(1, min(99, (int)($w['cap'] ?? 12)));
        $note   = mb_substr(trim((string)($w['note'] ?? '')), 0, 80);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            throw new RuntimeException("Invalid week range: $start → $end");
        }
        // Sanity: seven-day week
        $days = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days;
        if ($days !== 6) {
            throw new RuntimeException("Week range must be exactly 7 days: $start → $end");
        }

        $status = $labelToEnum[$label] ?? null;
        if ($status === null || !in_array($status, $validStatuses, true)) {
            throw new RuntimeException("Invalid status: $label");
        }

        // Recompute booked from confirmed orders so the stored count is trustworthy
        $bookedStmt->execute([$start, $end]);
        $booked = (int)$bookedStmt->fetchColumn();

        $upsertWeek->execute([
            'start'  => $start,
            'end'    => $end,
            'status' => $status,
            'cap'    => $cap,
            'booked' => $booked,
            'note'   => $note !== '' ? $note : null,
            'admin'  => $adminId,
        ]);

        $findWeek->execute([$start, $end]);
        $weekId = (int)$findWeek->fetchColumn();
        if ($weekId <= 0) throw new RuntimeException("Could not resolve week_id for $start");

        // Replace per-day rows
        $delDays->execute([$weekId]);
        for ($i = 0; $i < 7; $i++) {
            $on = !empty($active[$i]) ? 1 : 0;
            $dayDate = (new DateTimeImmutable($start))->modify("+$i days")->format('Y-m-d');
            $insDay->execute([$weekId, $dayDate, $on]);
        }

        $saved++;
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => "Saved $saved week(s)."]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('availability_save.php: ' . $e->getMessage());
    fail($e->getMessage() ?: 'Unable to save weekly availability.', 500);
}