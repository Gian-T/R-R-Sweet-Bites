<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

// This file is both the admin page AND the place its background requests go
// (load list, approve/decline, upload image, show image). Page visits get redirected to
// login if needed; background requests get a JSON error instead.
$isApi = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || isset($_GET['action']) || isset($_GET['file']);
$admin = require_role('admin', !$isApi);

const PAYMENT_SQL = 'SELECT p.*, o.id AS order_pk, o.order_no, o.request_no, o.requested_date, o.cake_size, o.flavor,
                            o.design_desc, o.ref_path, o.ref_original, o.base_price, o.rush_fee, u.full_name, u.email
                       FROM payments p
                       JOIN orders o ON o.id = p.order_id
                       JOIN users  u ON u.id = o.customer_id';

function peso(float $n): string { return '₱' . number_format($n, 2); }

/** Converts a database row into what this page's JavaScript expects. */
function map_payment(array $r): array {
    $total = (float)$r['base_price'] + (float)$r['rush_fee'];
    $dp    = round($total * 0.20, 2);
    $out = [
        'key'           => (string)$r['id'],
        'rq'            => $r['request_no'],
        'orderNo'       => $r['order_no'],
        'id39'          => (string)$r['order_pk'],
        'name'          => $r['full_name'],
        'email'         => $r['email'],
        'desc'          => ($r['ptype'] === 'Downpayment' ? '20% Downpayment' : 'Balance Payment') . ' · ' . $r['method'],
        'method'        => $r['method'],
        'ptype'         => $r['ptype'],
        'amount'        => (float)$r['amount'],
        'pay'           => $r['pay_no'],
        'ref'           => $r['reference_no'],
        'submitted'     => substr($r['submitted_at'], 0, 10),
        'status'        => $r['status'],
        'remarks'       => $r['remarks'] ?? '',
        'requestedDate' => date('M d, Y', strtotime($r['requested_date'])),
        'cakeSize'      => $r['cake_size'],
        'flavor'        => $r['flavor'],
        'design'        => $r['design_desc'],
        'refFile'       => $r['ref_original'] ?: 'No reference uploaded',
        'proofLabel'    => $r['method'] . ' mode of payment',
        'proofFile'     => $r['proof_original'] ?: 'No proof uploaded',
        'basePrice'     => (float)$r['base_price'],
        'rushFee'       => (float)$r['rush_fee'],
        'calc'          => [['Downpayment Due (20%)', peso($dp)], ['Balance Remaining', peso($total - $dp)]],
    ];
    if ($r['ref_path'])   { $out['refImage']   = 'admin-payment-verification.php?file=' . $r['id'] . '&kind=ref&v='   . md5($r['ref_path']);   $out['refFileName']   = $r['ref_original']; }
    if ($r['proof_path']) { $out['proofImage'] = 'admin-payment-verification.php?file=' . $r['id'] . '&kind=proof&v=' . md5($r['proof_path']); $out['proofFileName'] = $r['proof_original']; }
    return $out;
}

function load_stats(): array {
    $row = db()->query(
        "SELECT SUM(status='pending') AS pending,
                SUM(status='verified' AND DATE(verified_at) = CURDATE()) AS verified_today,
                SUM(status='rejected') AS rejected,
                COALESCE(SUM(CASE WHEN status='verified' AND YEARWEEK(verified_at, 1) = YEARWEEK(NOW(), 1) THEN amount END), 0) AS total_week
           FROM payments"
    )->fetch();
    return ['pending' => (int)$row['pending'], 'verifiedToday' => (int)$row['verified_today'],
            'rejected' => (int)$row['rejected'], 'totalWeek' => (float)$row['total_week']];
}

// ================= Background requests =================
if ($isApi) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ---- Show an uploaded image (kept outside public access, so it goes through here) ----
    if ($method === 'GET' && isset($_GET['file'])) {
        $kind = $_GET['kind'] ?? '';
        if (!in_array($kind, ['ref', 'proof'], true)) { http_response_code(400); exit; }
        $st = db()->prepare('SELECT p.proof_path, o.ref_path FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ?');
        $st->execute([(int)$_GET['file']]);
        $row  = $st->fetch();
        $name = $row[$kind === 'proof' ? 'proof_path' : 'ref_path'] ?? null;
        if (!$name || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $name) || !is_file(UPLOAD_DIR . '/' . $name)) { http_response_code(404); exit; }
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        header('Content-Type: ' . $types[pathinfo($name, PATHINFO_EXTENSION)]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize(UPLOAD_DIR . '/' . $name));
        readfile(UPLOAD_DIR . '/' . $name);
        exit;
    }

    // ---- Load the list + stats ----
    if ($method === 'GET' && ($_GET['action'] ?? '') === 'list') {
        $rows = db()->query(PAYMENT_SQL . " ORDER BY p.status = 'pending' DESC, p.submitted_at DESC")->fetchAll();
        json_out(['ok' => true, 'payments' => array_map('map_payment', $rows), 'stats' => load_stats()]);
    }

    if ($method === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
        // ---- Approve / decline ----
        $in = json_input();
        if (($in['action'] ?? '') !== 'decide') json_out(['ok' => false, 'message' => 'Unknown action.'], 400);

        $id       = (int)($in['id'] ?? 0);
        $decision = (string)($in['decision'] ?? '');
        $note     = trim((string)($in['note'] ?? ''));
        if (!in_array($decision, ['verified', 'rejected'], true)) json_out(['ok' => false, 'message' => 'Invalid decision.'], 422);
        if ($decision === 'rejected' && $note === '') json_out(['ok' => false, 'message' => 'Add a note so the customer knows what to fix.'], 422);
        if (mb_strlen($note) > 500) json_out(['ok' => false, 'message' => 'Note must be 500 characters or fewer.'], 422);

        $pdo = db();
        $pdo->beginTransaction();   // all three changes below succeed together, or none do
        try {
            $st = $pdo->prepare('SELECT p.id, p.status, p.order_id, o.customer_id, o.order_no
                                   FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ? FOR UPDATE');
            $st->execute([$id]);
            $p = $st->fetch();
            if (!$p) { $pdo->rollBack(); json_out(['ok' => false, 'message' => 'Payment not found.'], 404); }
            if ($p['status'] !== 'pending') { $pdo->rollBack(); json_out(['ok' => false, 'message' => 'This payment was already reviewed.'], 409); }

            $pdo->prepare('UPDATE payments SET status = ?, remarks = ?, verified_by = ?, verified_at = NOW() WHERE id = ?')
                ->execute([$decision, $note !== '' ? $note : null, $admin['id'], $id]);
            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')
                ->execute([$decision === 'verified' ? 'confirmed' : 'payment_rejected', $p['order_id']]);
            $pdo->prepare('INSERT INTO notifications (user_id, message) VALUES (?, ?)')->execute([$p['customer_id'],
                $decision === 'verified'
                    ? "Your payment for order #{$p['order_no']} was verified. Your order is confirmed!"
                    : "Your payment for order #{$p['order_no']} was rejected: {$note}"]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $st = $pdo->prepare(PAYMENT_SQL . ' WHERE p.id = ?');
        $st->execute([$id]);
        json_out(['ok' => true, 'payment' => map_payment($st->fetch()), 'stats' => load_stats()]);
    }

    if ($method === 'POST') {
        // ---- Upload an image (reference design or proof of payment) ----
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') json_out(['ok' => false, 'message' => 'Bad request.'], 400);
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $kind      = $_POST['kind'] ?? '';
        if (!in_array($kind, ['ref', 'proof'], true)) json_out(['ok' => false, 'message' => 'Invalid upload type.'], 422);

        $st = db()->prepare('SELECT p.status, p.proof_path, o.id AS order_id, o.ref_path FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ?');
        $st->execute([$paymentId]);
        $row = $st->fetch();
        if (!$row) json_out(['ok' => false, 'message' => 'Payment not found.'], 404);
        if ($row['status'] !== 'pending') json_out(['ok' => false, 'message' => 'This payment is already resolved.'], 409);

        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) json_out(['ok' => false, 'message' => 'Upload failed. Please try again.'], 400);
        if ($f['size'] > MAX_UPLOAD_BYTES) json_out(['ok' => false, 'message' => 'Image must be 5 MB or smaller.'], 413);

        // Check the real file content, not the name the browser claims.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext || @getimagesize($f['tmp_name']) === false) json_out(['ok' => false, 'message' => 'Only JPG, PNG or WebP images are allowed.'], 415);

        $stored   = bin2hex(random_bytes(16)) . '.' . $ext;   // random name on disk
        $original = mb_substr(preg_replace('/[^\p{L}\p{N} ._()-]/u', '_', basename($f['name'])), 0, 150);
        if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $stored)) throw new RuntimeException('Could not save the upload (is the uploads folder writable?).');

        if ($kind === 'proof') {
            $old = $row['proof_path'];
            db()->prepare('UPDATE payments SET proof_path = ?, proof_original = ? WHERE id = ?')->execute([$stored, $original, $paymentId]);
        } else {
            $old = $row['ref_path'];
            db()->prepare('UPDATE orders SET ref_path = ?, ref_original = ? WHERE id = ?')->execute([$stored, $original, $row['order_id']]);
        }
        if ($old && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $old)) @unlink(UPLOAD_DIR . '/' . $old);
        json_out(['ok' => true, 'fileName' => $original, 'url' => "admin-payment-verification.php?file={$paymentId}&kind={$kind}&v=" . md5($stored)]);
    }

    json_out(['ok' => false, 'message' => 'Bad request.'], 400);
}

// ================= The page itself =================
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(explode(' ', $admin['full_name']), 0, 2))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payment Verification | R&amp;R Sweet Bites Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;500;600;700&family=Platypi:wght@500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
  --cream:#fff4ef; --panel:#ffffff; --border:#f0dcd6; --pink:#d9788a; --pink-soft:#f6cbd3;
  --pink-pale:#fbe6e2; --button:#db7f8e; --text:#4a2a2e; --muted:#8a6b6f; --ph:#f6cbd3;
  --amber:#b5792c; --amber-bg:#fbeed9; --blue:#2a6ab0; --green:#1e6b3a; --red:#b3263e; --red-bg:#fde8ec;
  --font-header:"Playfair Display",Georgia,"Times New Roman",serif;
  --font-button:"Platypi",Georgia,"Times New Roman",serif;
  --font-text:"Jost","Helvetica Neue",Arial,sans-serif;
}
*{box-sizing:border-box;margin:0}
body{min-height:100vh;background:var(--cream);color:var(--text);font-family:var(--font-text);font-size:14px}
a{color:inherit}
img[src=""]{opacity:0}
.ph{overflow:hidden;background:var(--ph)}
.ph img{display:block;width:100%;height:100%;object-fit:cover}
button{font-family:inherit;cursor:pointer}
:focus-visible{outline:3px solid rgba(217,120,138,.55);outline-offset:2px}

/* ---------- Top bar ---------- */
.topbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;padding:10px 16px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:40}
.menu-btn{display:none;flex:none;width:38px;height:38px;border:0;background:none;padding:8px;border-radius:8px}
.menu-btn span{display:block;height:2.5px;margin:5px 0;border-radius:2px;background:var(--text)}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none}
.brand .ph{flex:none;width:40px;height:40px;border-radius:50%}
.brand-name{font-family:var(--font-header);font-weight:700;font-size:1.15rem;color:var(--text);white-space:nowrap}
.workspace-pill{margin-left:6px;padding:6px 14px;border:1px solid var(--pink);border-radius:999px;background:var(--pink-pale);color:var(--pink);font:600 .72rem var(--font-button);letter-spacing:.04em;white-space:nowrap}
.topbar .spacer{flex:1}
.who{display:flex;align-items:center;gap:10px}
.who-text{text-align:right;line-height:1.25}
.who-name{font-weight:700;font-size:.92rem}
.who-role{font-size:.78rem;color:var(--muted)}
.avatar{flex:none;display:grid;place-items:center;width:38px;height:38px;border-radius:50%;background:var(--pink-soft);color:#7a3a45;font:700 .85rem var(--font-button)}

/* ---------- Layout ---------- */
.shell{display:flex;align-items:flex-start}
.sidebar{flex:none;width:230px;padding:20px 14px;border-right:1px solid var(--border);background:var(--panel);min-height:calc(100vh - 61px);position:sticky;top:61px}
.side-group{margin-bottom:20px}
.side-label{padding:0 12px;margin-bottom:6px;font-size:.7rem;font-weight:700;letter-spacing:.06em;color:var(--muted);text-transform:uppercase}
.side-link{display:block;padding:9px 12px;margin-bottom:2px;border-radius:8px;font-size:.85rem;font-weight:500;color:var(--text);text-decoration:none}
.side-link:hover{background:var(--pink-pale)}
.side-link[aria-current="page"]{background:var(--pink-soft);color:#7a3a45;font-weight:700}

.main{flex:1;min-width:0;padding:26px 28px 60px}
.crumb{display:none;margin-bottom:8px;font-size:.8rem;font-weight:600}
.crumb.show{display:block}
.crumb a{color:var(--pink);text-decoration:none}
.crumb a:hover{text-decoration:underline}
.crumb .sep{margin:0 6px;color:var(--muted)}
.crumb .current{color:var(--muted)}
.page-title{font-family:var(--font-header);font-size:1.7rem;font-weight:700}
.page-sub{margin-top:4px;color:var(--muted);font-size:.88rem}

/* ---------- Stat cards ---------- */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:20px}
.stat{padding:14px 16px;border:1px solid var(--border);border-radius:12px;background:var(--panel)}
.stat-label{font-size:.68rem;font-weight:700;letter-spacing:.06em;color:var(--muted);text-transform:uppercase}
.stat-value{margin-top:8px;font-family:var(--font-header);font-size:1.6rem;font-weight:700}
.stat-hint{margin-top:4px;font-size:.76rem;font-weight:600}
.stat-hint.amber{color:var(--amber)}
.stat-hint.blue{color:var(--blue)}
.stat-hint.muted{color:var(--muted)}

/* ---------- Filters ---------- */
.filters{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:18px;padding:14px 16px;border-radius:12px;background:var(--pink-pale)}
.filters-label{font-size:.78rem;font-weight:700;color:var(--muted)}
.filters select, .search{height:38px;border:1px solid var(--border);border-radius:999px;background:#fff;font:500 .82rem var(--font-text);color:var(--text)}
.filters select{padding:0 30px 0 14px;min-width:170px;appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234a2a2e' stroke-width='2'><path d='M6 9l6 6 6-6'/></svg>");background-repeat:no-repeat;background-position:right 10px center;background-size:14px}
.search-wrap{flex:1;min-width:220px;position:relative}
.search-wrap svg{position:absolute;left:13px;top:50%;transform:translateY(-50%);width:15px;height:15px;stroke:var(--muted);fill:none;stroke-width:2}
.search{width:100%;padding:0 14px 0 36px}

/* ---------- Payment list ---------- */
.list{display:flex;flex-direction:column;gap:12px;margin-top:16px}
.item{display:flex;align-items:center;gap:16px;padding:14px 18px;border:1px solid var(--border);border-radius:12px;background:var(--panel);text-align:left;width:100%}
.item-main,.item-name,.item-email,.item-desc,.item-amount{display:block}
.item-id{display:block}
.item-id{flex:none;width:60px;text-align:center;font-weight:700;color:var(--pink);font-size:.8rem;line-height:1.3}
.item-id small{display:block;font-weight:500;color:var(--muted);font-size:.68rem}
.item-main{flex:1;min-width:0}
.item-name{font-weight:700;font-size:.92rem}
.item-email{color:var(--muted);font-size:.76rem}
.item-desc{margin-top:2px;font-size:.78rem;color:var(--muted)}
.item-amount{flex:none;text-align:right;min-width:90px}
.item-amount .lbl{display:block;font-size:.65rem;font-weight:700;letter-spacing:.05em;color:var(--muted);text-transform:uppercase}
.item-amount .val{font-weight:700;font-size:.95rem}
.badge{flex:none;padding:5px 12px;border-radius:999px;font-size:.72rem;font-weight:700}
.badge.pending{background:var(--pink-pale);color:var(--pink)}
.badge.verified{background:#e6f4ea;color:var(--green)}
.badge.rejected{background:var(--red-bg);color:var(--red)}
.item-ref{flex:none;width:56px;font-size:.72rem;color:var(--muted);text-align:center}
.review-btn{flex:none;padding:8px 18px;border:0;border-radius:999px;background:var(--pink-soft);color:#7a3a45;font:700 .78rem var(--font-button);transition:background .15s}
.review-btn:hover{background:#eeb7c3}
.chevron{flex:none;width:18px;height:18px;stroke:var(--muted);fill:none;stroke-width:2}
.empty{padding:34px;text-align:center;color:var(--muted);font-size:.88rem;border:1px dashed var(--border);border-radius:12px}

/* ---------- Detail (review) page ---------- */
.detail{display:none;margin-top:16px}
.detail.show{display:block}
.list-view.hide{display:none}
.detail-toprow{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.back-link{display:inline-flex;align-items:center;gap:6px;border:0;background:none;padding:6px 0;color:var(--pink);font:700 .82rem var(--font-text)}
.back-link:hover{text-decoration:underline}
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:6px 14px;border-radius:999px;font:700 .74rem var(--font-button)}
.status-pill svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2.5}
.detail-head{margin-top:16px}
.detail-head h2{font-family:var(--font-header);font-size:1.25rem;font-weight:700}
.detail-head p{margin-top:2px;color:var(--muted);font-size:.85rem}
.detail-grid{display:grid;grid-template-columns:1.3fr 1fr;gap:18px;margin-top:16px;align-items:start}
.dcard{border:1px solid var(--border);border-radius:14px;background:var(--panel);padding:18px 20px}
.dcard-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap}
.dcard-head h3{font-family:var(--font-header);font-size:1.02rem;font-weight:700}
.dcard-head .meta{font-size:.74rem;color:var(--muted)}
.dcard-sub{margin-top:2px;font-size:.78rem;color:var(--muted)}
.rows{margin-top:14px;display:flex;flex-direction:column;gap:11px}
.row-line{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;font-size:.82rem}
.row-line dt{flex:none;font-weight:700;color:var(--muted);letter-spacing:.03em;font-size:.72rem;text-transform:uppercase}
.row-line dd{margin:0;text-align:right;font-weight:600;max-width:62%}
.row-line dd.quote{font-style:italic;font-weight:500}
.row-line dd .mini-pill{display:inline-block;padding:3px 10px;border-radius:999px;background:var(--pink-pale);color:var(--pink);font-weight:700;font-size:.76rem}
.upload-block{margin-top:16px}
.upload-block > span{display:block;margin-bottom:6px;font-size:.74rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
.upload-box{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;height:96px;border-radius:10px;text-align:center;padding:8px;position:relative;border:1.5px dashed var(--pink-soft);cursor:pointer;transition:border-color .15s,background .15s}
.upload-box:hover{border-color:var(--pink)}
.upload-box strong{font-size:.78rem}
.upload-box span{font-size:.7rem;color:#7a4b52}
.upload-box.has-image{height:auto;display:block;border-style:solid;border-color:var(--border);padding:0;cursor:zoom-in}
.upload-box.has-image img{display:block;width:100%;height:auto;max-height:280px;object-fit:contain;background:#f3e4e0}
.upload-box .upload-caption{position:absolute;left:0;right:0;bottom:0;padding:5px 8px;background:rgba(58,35,39,.68);color:#fff;font-size:.66rem;font-weight:600;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.upload-replace{position:absolute;top:8px;right:8px;padding:5px 12px;border:0;border-radius:999px;background:rgba(58,35,39,.68);color:#fff;font:700 .68rem var(--font-button)}
.upload-replace:hover{background:rgba(58,35,39,.85)}
.upload-box.ph.readonly{cursor:default;border-style:solid;border-color:var(--border)}
.upload-box.ph.readonly:hover{border-color:var(--border)}
.upload-box input[type="file"]{position:absolute;width:1px;height:1px;overflow:hidden;opacity:0}

.lightbox{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(20,10,12,.85);z-index:300;padding:26px}
.lightbox.show{display:flex}
.lightbox img{display:block;max-width:92vw;max-height:86vh;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.45)}
.lightbox-close{position:absolute;top:18px;right:20px;width:40px;height:40px;border:0;border-radius:50%;background:rgba(255,255,255,.16);color:#fff;font-size:1.3rem;line-height:1;display:grid;place-items:center}
.lightbox-close:hover{background:rgba(255,255,255,.3)}
.lightbox-caption{position:absolute;bottom:22px;left:50%;transform:translateX(-50%);max-width:90vw;padding:6px 16px;border-radius:999px;background:rgba(0,0,0,.45);color:#fff;font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dec-sub{margin-top:4px;font-size:.78rem;color:var(--muted)}
.quote-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px;padding:11px 15px;border-radius:10px;font-size:.85rem}
.quote-row.pink{background:var(--pink-pale)}
.quote-row.white{border:1px solid var(--border);background:#fff}
.quote-row .qlabel{font-weight:700}
.quote-row .qvalue{font-weight:700}
.quote-row.pink .qvalue{color:var(--pink)}
.subtotal-note{margin-top:12px;font-size:.74rem;color:var(--muted)}
.calc-row{margin-top:8px}
.note-label{display:block;margin-top:16px;margin-bottom:6px;font-size:.78rem;font-weight:700}
.note-box{width:100%;min-height:78px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;font:500 .82rem var(--font-text);resize:vertical}
.note-error{display:block;min-height:16px;margin-top:4px;color:var(--red);font-size:.74rem;font-weight:600}
.decision-actions{margin-top:14px;display:flex;flex-direction:column;gap:8px}
.dbtn{width:100%;padding:11px;border-radius:999px;font:700 .84rem var(--font-button);border:1.5px solid transparent}
.dbtn.approve{background:var(--button);color:#fff}
.dbtn.approve:hover{background:#cf6c7d}
.dbtn.decline{background:#fff;border-color:var(--border);color:var(--text)}
.dbtn.decline:hover{background:var(--red-bg);border-color:#e79aab}
.dbtn:disabled{opacity:.55;cursor:default}
.resolved-banner{margin-top:14px;padding:12px 14px;border-radius:10px;font-size:.82rem;font-weight:600}
.resolved-banner.verified{background:#e6f4ea;color:var(--green)}
.resolved-banner.rejected{background:var(--red-bg);color:var(--red)}

.toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,10px);opacity:0;padding:11px 20px;border-radius:999px;background:#3a2327;color:#fff;font-size:.82rem;font-weight:600;transition:opacity .2s,transform .2s;z-index:200;max-width:90vw;text-align:center}
.toast.show{opacity:1;transform:translate(-50%,0)}

/* ---------- Responsive ---------- */
@media (max-width:1080px){ .stats{grid-template-columns:repeat(2,1fr)} }
@media (max-width:900px){
  .menu-btn{display:block}
  .who-text{display:none}
  .sidebar{position:fixed;top:61px;left:0;bottom:0;transform:translateX(-100%);transition:transform .2s;z-index:60}
  .sidebar.open{transform:translateX(0);box-shadow:10px 0 30px rgba(0,0,0,.15)}
  .main{padding:18px}
  .detail-grid{grid-template-columns:1fr}
}
@media (max-width:480px){
  .workspace-pill{display:none}
  .brand-name{font-size:1rem}
}
@media (max-width:720px){
  .item{flex-direction:column;align-items:stretch;gap:8px;position:relative}
  .item-id{position:absolute;top:14px;right:18px;width:auto;text-align:right}
  .item-main{padding-right:44px}
  .item-amount{text-align:left}
  .item-ref{width:auto}
  .chevron{position:absolute;bottom:14px;right:18px}
  .filters select,.search-wrap{width:100%}
  .row-line{flex-direction:column;gap:2px}
  .row-line dd{max-width:100%;text-align:left}
}
</style>
</head>
<body>

<header class="topbar">
  <button class="menu-btn" id="menuBtn" type="button" aria-expanded="false" aria-controls="sidebar" aria-label="Toggle menu"><span></span><span></span><span></span></button>
  <a class="brand" href="#"><span class="ph"><img src="" alt=""></span><span class="brand-name">R&amp;R Sweet Bites</span></a>
  <span class="workspace-pill">ADMIN WORKSPACE</span>
  <div class="spacer"></div>
  <div class="who">
    <div class="who-text"><div class="who-name"><?= $h($admin['full_name']) ?></div><div class="who-role">Admin</div></div>
    <div class="avatar" aria-hidden="true"><?= $h($initials) ?></div>
  </div>
</header>

<div class="shell">
  <nav class="sidebar" id="sidebar" aria-label="Admin sections">
    <div class="side-group">
      <div class="side-label">Communication</div>
      <a class="side-link" href="#">Messages</a>
      <a class="side-link" href="#">Notifications</a>
    </div>
    <div class="side-group">
      <div class="side-label">Order Management</div>
      <a class="side-link" href="#">Design Gallery</a>
      <a class="side-link" href="#">Order Request</a>
      <a class="side-link" href="#">Week Availability</a>
      <a class="side-link" href="#" aria-current="page">Payment Verification</a>
      <a class="side-link" href="#">Cancellation Requests</a>
    </div>
    <div class="side-group">
      <div class="side-label">Store Management</div>
      <a class="side-link" href="#">Sales &amp; Performance</a>
      <form method="post" action="logout.php"><button class="side-link" type="submit" style="background:none;border:0;width:100%;text-align:left;font:inherit;cursor:pointer">Log out</button></form>
    </div>
  </nav>

  <main class="main">
    <p class="crumb" id="crumb"><a href="#" id="crumbHome">Payment Verification</a><span class="sep">/</span><span class="current">Approval Review</span></p>
    <h1 class="page-title">Payment Verification</h1>
    <p class="page-sub">Manually review submitted proof of payment, approve or reject, and cascade order status updates.</p>

    <section class="stats" aria-label="Summary">
      <div class="stat"><div class="stat-label">Awaiting Review</div><div class="stat-value" id="statPending">0</div><div class="stat-hint amber">Action Required</div></div>
      <div class="stat"><div class="stat-label">Verified Today</div><div class="stat-value" id="statVerified">0</div><div class="stat-hint blue">Payments OK</div></div>
      <div class="stat"><div class="stat-label">Rejected</div><div class="stat-value" id="statRejected">0</div><div class="stat-hint amber">Need Resubmit</div></div>
      <div class="stat"><div class="stat-label">Total Verified (Week)</div><div class="stat-value" id="statTotal">₱0</div><div class="stat-hint muted">This week</div></div>
    </section>

    <section class="filters" aria-label="Filters">
      <span class="filters-label">Filters:</span>
      <select id="statusFilter" aria-label="Filter by status">
        <option value="pending" selected>Pending Verification</option>
        <option value="verified">Verified</option>
        <option value="rejected">Rejected</option>
        <option value="all">All Statuses</option>
      </select>
      <select id="typeFilter" aria-label="Filter by payment method">
        <option value="all" selected>All Types</option>
        <option value="GCash">GCash</option>
        <option value="Bank Transfer">Bank Transfer</option>
        <option value="Cash">Cash</option>
      </select>
      <div class="search-wrap">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <input class="search" id="searchBox" type="search" placeholder="Search order ID / reference...">
      </div>
    </section>

    <section class="list-view" id="listView">
      <div class="list" id="list" aria-label="Payment submissions"></div>
    </section>

    <section class="detail" id="detailView" aria-live="polite"></section>
  </main>
</div>

<div class="toast" id="toast"></div>

<div class="lightbox" id="lightbox" aria-hidden="true">
  <button class="lightbox-close" id="lightboxClose" type="button" aria-label="Close">&times;</button>
  <img id="lightboxImg" src="" alt="Full size preview">
  <span class="lightbox-caption" id="lightboxCaption"></span>
</div>

<script>
(function () {
  "use strict";
  const PHP = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP", maximumFractionDigits: 0 });
  const PHP2 = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP", minimumFractionDigits: 2 });
  const DATE = (iso) => new Date(iso + "T00:00:00").toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));

  // Data now comes from the database from the PHP at the top of this file (admin session required).
  let payments = [];
  let stats = { pending: 0, verifiedToday: 0, rejected: 0, totalWeek: 0 };

  async function api(url, body) {
    const opts = body
      ? { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }
      : { credentials: "same-origin", headers: { "Accept": "application/json" } };
    const r = await fetch(url, opts);
    const d = await r.json().catch(() => ({}));
    if (r.status === 401 || r.status === 403) { window.location.href = "login.php"; }   // session expired
    return { ok: r.ok && d.ok !== false, d };
  }

  async function loadPayments() {
    try {
      const { ok, d } = await api("admin-payment-verification.php?action=list");
      if (!ok) throw new Error(d.message);
      payments = d.payments; stats = d.stats;
      renderStats(); renderList();
    } catch (e) {
      list.innerHTML = '<div class="empty">Could not load payments. Please refresh.</div>';
    }
  }

  const $ = (id) => document.getElementById(id);
  const list = $("list"), listView = $("listView"), detailView = $("detailView"), crumb = $("crumb");
  const statusFilter = $("statusFilter"), typeFilter = $("typeFilter"), searchBox = $("searchBox");

  /* ---------- Stats (calculated by the server) ---------- */
  function renderStats() {
    $("statPending").textContent = stats.pending;
    $("statVerified").textContent = stats.verifiedToday;
    $("statRejected").textContent = stats.rejected;
    $("statTotal").textContent = PHP.format(stats.totalWeek);
  }

  /* ---------- List ---------- */
  function matches(p) {
    if (statusFilter.value !== "all" && p.status !== statusFilter.value) return false;
    if (typeFilter.value !== "all" && p.method !== typeFilter.value) return false;
    const q = searchBox.value.trim().toLowerCase();
    if (q && !(p.rq.toLowerCase().includes(q) || p.ref.toLowerCase().includes(q) || p.pay.toLowerCase().includes(q) || p.orderNo.toLowerCase().includes(q))) return false;
    return true;
  }

  function badge(status) {
    if (status === "verified") return '<span class="badge verified">Verified</span>';
    if (status === "rejected") return '<span class="badge rejected">Rejected</span>';
    return '<span class="badge pending">Pending</span>';
  }

  function renderList() {
    const rows = payments.filter(matches);
    list.innerHTML = rows.length ? "" : '<div class="empty">No payment submissions match these filters.</div>';
    rows.forEach((p) => {
      const row = document.createElement("button");
      row.type = "button";
      row.className = "item";
      row.innerHTML = `
        <span class="item-id">${p.id39}<small>${p.rq}</small></span>
        <span class="item-main">
          <span class="item-name">${esc(p.name)}</span>
          <span class="item-email">${esc(p.email)}</span>
          <span class="item-desc">${esc(p.desc)}</span>
        </span>
        <span class="item-amount"><span class="lbl">Amount</span><span class="val">${PHP.format(p.amount)}</span></span>
        ${badge(p.status)}
        <span class="item-ref">${p.pay}</span>
        <span class="review-btn">${p.status === "pending" ? "Review" : "View"}</span>
        <svg class="chevron" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>
      `;
      row.addEventListener("click", () => openDetail(p.key));
      list.appendChild(row);
    });
  }

  [statusFilter, typeFilter].forEach((el) => el.addEventListener("change", renderList));
  searchBox.addEventListener("input", renderList);

  /* ---------- Detail (Approval Review) page - FR-17 ---------- */
  function statusPill(status) {
    if (status === "verified") return `<span class="status-pill resolved-banner verified" style="padding:6px 14px">${check}Verified</span>`;
    if (status === "rejected") return `<span class="status-pill resolved-banner rejected" style="padding:6px 14px">${close_i}Rejected</span>`;
    return `<span class="status-pill badge pending">${clock_i}Pending Verification</span>`;
  }

  function uploadBoxTemplate(p, kind, placeholderLabel, resolved) {
    const image = p[kind + "Image"];
    const fileName = p[kind + "FileName"] || p[kind + "File"];
    const boxId = kind + "Box_" + p.key;
    const inputId = kind + "Upload_" + p.key;
    const replaceId = kind + "Replace_" + p.key;
    if (image) {
      return `
        <div class="upload-box has-image" id="${boxId}" role="button" tabindex="0" aria-label="View ${esc(placeholderLabel)} full size">
          <img src="${image}" alt="${esc(placeholderLabel)} preview">
          <span class="upload-caption">${esc(fileName)}</span>
          ${resolved ? "" : `
            <button type="button" class="upload-replace" id="${replaceId}">Replace</button>
            <input type="file" accept="image/*" id="${inputId}" data-field="${kind}">
          `}
        </div>`;
    }
    if (resolved) {
      return `<div class="upload-box ph readonly" id="${boxId}"><strong>${placeholderLabel}</strong><span>${esc(fileName)}</span></div>`;
    }
    return `
      <label class="upload-box ph" id="${boxId}" for="${inputId}">
        <strong>${placeholderLabel}</strong><span>${esc(fileName)} &middot; Click to upload photo</span>
        <input type="file" accept="image/*" id="${inputId}" data-field="${kind}">
      </label>`;
  }

  function wireUploadBox(p, kind, placeholderLabel) {
    const box = $(kind + "Box_" + p.key);
    const input = $(kind + "Upload_" + p.key);
    const replaceBtn = $(kind + "Replace_" + p.key);

    if (input) {
      input.addEventListener("change", () => {
        const file = input.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append("payment_id", p.key); fd.append("kind", kind); fd.append("file", file);
        fetch("admin-payment-verification.php", { method: "POST", body: fd, credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } })
          .then((r) => r.json().then((d) => ({ ok: r.ok && d.ok, d })))
          .then(({ ok, d }) => {
            if (!ok) { showToast(d.message || "Upload failed."); input.value = ""; return; }
            p[kind + "Image"] = d.url;
            p[kind + "FileName"] = d.fileName;
            $(kind + "Box_" + p.key).outerHTML = uploadBoxTemplate(p, kind, placeholderLabel, p.status !== "pending");
            wireUploadBox(p, kind, placeholderLabel);
          })
          .catch(() => showToast("Upload failed. Check your connection."));
      });
    }
    if (replaceBtn) {
      replaceBtn.addEventListener("click", (e) => { e.stopPropagation(); input.click(); });
    }
    if (box && p[kind + "Image"]) {
      const openFull = () => openLightbox(p[kind + "Image"], p[kind + "FileName"] || p[kind + "File"]);
      box.addEventListener("click", openFull);
      box.addEventListener("keydown", (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); openFull(); } });
    }
  }

  function detailTemplate(p) {
    const resolved = p.status !== "pending";
    return `
      <div class="detail-toprow">
        <button class="back-link" type="button" id="backBtn">&larr; Back to list</button>
        ${statusPill(p.status)}
      </div>
      <div class="detail-head">
        <h2>Payment Review &middot; Order #${p.orderNo}</h2>
        <p>${esc(p.name)} &middot; ${p.ptype} (20%)</p>
      </div>
      <div class="detail-grid">
        <div class="dcard">
          <div class="dcard-head"><h3>Request Details</h3><span class="meta">Submitted ${DATE(p.submitted)}</span></div>
          <dl class="rows">
            <div class="row-line"><dt>Customer name</dt><dd>${esc(p.name)}</dd></div>
            <div class="row-line"><dt>Requested date</dt><dd><span class="mini-pill">${p.requestedDate}</span></dd></div>
            <div class="row-line"><dt>Cake size &amp; tier</dt><dd>${esc(p.cakeSize)}</dd></div>
            <div class="row-line"><dt>Flavor &amp; filling</dt><dd>${esc(p.flavor)}</dd></div>
            <div class="row-line"><dt>Design description</dt><dd class="quote">&ldquo;${esc(p.design)}&rdquo;</dd></div>
          </dl>
          <div class="upload-block">
            <span>Reference design</span>
            ${uploadBoxTemplate(p, "ref", "Reference design uploaded", resolved)}
          </div>
          <div class="upload-block">
            <span>Proof of payment</span>
            ${uploadBoxTemplate(p, "proof", esc(p.proofLabel), resolved)}
          </div>
        </div>

        <div class="dcard">
          <h3>Verification Decision</h3>
          <p class="dec-sub">Review payment against quoted totals, then approve or reject.</p>
          <div class="quote-row pink"><span class="qlabel">Base Price Quote</span><span class="qvalue">${PHP2.format(p.basePrice)}</span></div>
          <div class="quote-row white"><span class="qlabel">Rush Processing Fee</span><span class="qvalue">${PHP2.format(p.rushFee)}</span></div>
          <p class="subtotal-note">Recommended subtotal for base + rush</p>
          ${p.calc.map(([label, value]) => `<div class="quote-row pink calc-row"><span class="qlabel">${label}</span><span class="qvalue">${value}</span></div>`).join("")}

          ${resolved ? `
            <div class="resolved-banner ${p.status}">${p.status === "verified" ? "Payment verified. Customer has been notified." : "Payment rejected. Customer has been notified."}${p.remarks ? " &mdash; \u201c" + esc(p.remarks) + "\u201d" : ""}</div>
          ` : `
            <label class="note-label" for="note">Message / personal note to customer</label>
            <textarea class="note-box" id="note" placeholder="Provide a note to the customer regarding approval or decline (required if declining)."></textarea>
            <span class="note-error" id="noteError"></span>
            <div class="decision-actions">
              <button class="dbtn approve" id="approveBtn" type="button">Approve &amp; Verify Payment</button>
              <button class="dbtn decline" id="declineBtn" type="button">Decline Request</button>
            </div>
          `}
        </div>
      </div>
    `;
  }

  function openDetail(key) {
    const p = payments.find((x) => x.key === key);
    if (!p) return;
    detailView.innerHTML = detailTemplate(p);
    listView.classList.add("hide");
    detailView.classList.add("show");
    crumb.classList.add("show");
    window.scrollTo({ top: 0, behavior: "smooth" });

    $("backBtn").addEventListener("click", closeDetail);
    wireUploadBox(p, "ref", "Reference design uploaded");
    wireUploadBox(p, "proof", p.proofLabel);
    if (p.status === "pending") {
      $("approveBtn").addEventListener("click", () => resolve(p, "verified"));
      $("declineBtn").addEventListener("click", () => resolve(p, "rejected"));
    }
  }

  function closeDetail() {
    detailView.classList.remove("show");
    listView.classList.remove("hide");
    crumb.classList.remove("show");
    detailView.innerHTML = "";
  }

  async function resolve(p, decision) {
    const note = $("note").value.trim();
    if (decision === "rejected" && !note) {
      $("noteError").textContent = "Add a note so the customer knows what to fix.";
      $("note").focus();
      return;
    }
    const buttons = detailView.querySelectorAll(".dbtn");
    buttons.forEach((b) => { b.disabled = true; });
    try {
      const { ok, d } = await api("admin-payment-verification.php", { action: "decide", id: p.key, decision, note });
      if (!ok) {
        $("noteError").textContent = d.message || "Could not save the decision. Please try again.";
        buttons.forEach((b) => { b.disabled = false; });
        return;
      }
      Object.assign(p, d.payment);
      stats = d.stats;
      renderStats(); renderList(); closeDetail();
      showToast("Payment " + (decision === "verified" ? "verified" : "rejected") + ". " + p.name.split(" ")[0] + " has been notified.");
    } catch (e) {
      $("noteError").textContent = "Can't reach the server. Please try again.";
      buttons.forEach((b) => { b.disabled = false; });
    }
  }

  /* ---------- Lightbox (full-size image view) ---------- */
  const lightbox = $("lightbox"), lightboxImg = $("lightboxImg"), lightboxCaption = $("lightboxCaption"), lightboxClose = $("lightboxClose");
  function openLightbox(src, caption) {
    lightboxImg.src = src;
    lightboxCaption.textContent = caption || "";
    lightbox.classList.add("show");
    lightbox.setAttribute("aria-hidden", "false");
  }
  function closeLightbox() {
    lightbox.classList.remove("show");
    lightbox.setAttribute("aria-hidden", "true");
    lightboxImg.src = "";
  }
  lightboxClose.addEventListener("click", closeLightbox);
  lightbox.addEventListener("click", (e) => { if (e.target === lightbox) closeLightbox(); });
  document.addEventListener("keydown", (e) => { if (e.key === "Escape") closeLightbox(); });

  const crumbHome = $("crumbHome");
  crumbHome.addEventListener("click", (e) => { e.preventDefault(); closeDetail(); });

  const toast = $("toast");
  let toastTimer;
  function showToast(msg) {
    toast.textContent = msg; toast.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove("show"), 2800);
  }

  /* ---------- Responsive sidebar ---------- */
  const sidebar = $("sidebar"), menuBtn = $("menuBtn");
  menuBtn.addEventListener("click", () => {
    const open = sidebar.classList.toggle("open");
    menuBtn.setAttribute("aria-expanded", String(open));
  });
  document.addEventListener("click", (e) => {
    if (window.innerWidth > 900) return;
    if (!sidebar.contains(e.target) && !menuBtn.contains(e.target)) sidebar.classList.remove("open");
  });

  const check = "<svg viewBox=\"0 0 24 24\"><path d=\"M20 6 9 17l-5-5\"/></svg>";
  const close_i = "<svg viewBox=\"0 0 24 24\"><path d=\"M18 6 6 18M6 6l12 12\"/></svg>";
  const clock_i = "<svg viewBox=\"0 0 24 24\"><circle cx=\"12\" cy=\"12\" r=\"9\"/><path d=\"M12 7v5l3 3\"/></svg>";

  loadPayments();
})();
</script>
</body>
</html>
