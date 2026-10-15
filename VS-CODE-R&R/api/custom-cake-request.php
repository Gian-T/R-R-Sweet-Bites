<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$customerId = (($_SESSION['role'] ?? '') === 'customer')
    ? filter_var($_SESSION['uid'] ?? null, FILTER_VALIDATE_INT)
    : false;
if (!$customerId) {
    respond(401, ['success' => false, 'error' => 'Please log in before submitting a cake request.']);
}
if (!csrf_ok()) {
    respond(403, ['success' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
}

try {
    $pdo = db();

    // Customer identity always comes from the authenticated session/profile, never hidden form fields.
    $customerStmt = $pdo->prepare(
        'SELECT customer_id FROM customer WHERE customer_id = :customer_id'
    );
    $customerStmt->execute(['customer_id' => $customerId]);
    if (!$customerStmt->fetchColumn()) {
        respond(401, ['success' => false, 'error' => 'Your customer account could not be verified. Please log in again.']);
    }

    $cakeType = trim((string)($_POST['cake_type'] ?? 'cake'));
    if (!in_array($cakeType, ['cake', 'cupcake', 'number_shaped_cake'], true)) {
        respond(422, ['success' => false, 'error' => 'Choose a valid cake type.']);
    }

    $occasion = trim((string)($_POST['occasion'] ?? ''));
    if (!in_array($occasion, ['birthday', 'wedding', 'anniversary', 'others'], true)) {
        respond(422, ['success' => false, 'error' => 'Choose a valid occasion.']);
    }

    $numTiers = filter_var($_POST['num_tiers'] ?? null, FILTER_VALIDATE_INT);
    $numLayers = filter_var($_POST['num_layers'] ?? null, FILTER_VALIDATE_INT);
    // Kept only because the existing table requires this legacy field; excluded from complexity.
    $intricacy = 3;
    if ($numTiers === false || $numTiers < 1 || $numTiers > 4 ||
        $numLayers === false || $numLayers < 2 || $numLayers > 4) {
        respond(422, ['success' => false, 'error' => 'Choose valid tiers and layers.']);
    }

    $heightInput = strtolower(trim((string)($_POST['cake_height'] ?? '')));
    $widthInput = strtolower(trim((string)($_POST['cake_width'] ?? '')));
    $heightNumber = filter_var(preg_replace('/\s*in$/', '', $heightInput), FILTER_VALIDATE_INT);
    $widthNumber = filter_var(preg_replace('/\s*in$/', '', $widthInput), FILTER_VALIDATE_INT);
    if ($heightNumber === false || !in_array($heightNumber, [4,5,6,7,8], true) ||
        $widthNumber === false || !in_array($widthNumber, [4,6,8,10,12], true)) {
        respond(422, ['success' => false, 'error' => 'Choose a valid overall cake height and width.']);
    }
    $cakeHeight = $heightNumber . 'in';
    $cakeWidth = $widthNumber . 'in';

    $preferredDateInput = trim((string)($_POST['preferred_date'] ?? ''));
    $preferredDate = DateTimeImmutable::createFromFormat('!Y-m-d', $preferredDateInput);
    if (!$preferredDate || $preferredDate->format('Y-m-d') !== $preferredDateInput ||
        $preferredDateInput < date('Y-m-d')) {
        respond(422, ['success' => false, 'error' => 'Choose a valid date that is today or later.']);
    }

    // A requested date must belong to an open weekly-availability range.
    $weekStmt = $pdo->prepare(
        "SELECT w.week_id
           FROM weekly_availability w
           LEFT JOIN weekly_availability_day d
                  ON d.week_id = w.week_id AND d.day_date = :date
          WHERE w.status = 'open'
            AND w.week_start_date <= :date
            AND w.week_end_date   >= :date
            AND (d.is_active IS NULL OR d.is_active = 1)
          LIMIT 1"
    );
    $weekStmt->execute(['date' => $preferredDateInput]);
    $weekId = $weekStmt->fetchColumn();
    if ($weekId === false) {
        respond(422, ['success' => false, 'error' => 'That date is not currently available. Please choose an open date.']);
    }
    $weekId = (int)$weekId;

    $flavor = trim((string)($_POST['flavor'] ?? ''));
    $flavorVariant = trim((string)($_POST['flavor_variant'] ?? ''));
    $frosting = trim((string)($_POST['frosting'] ?? ''));
    $filling = trim((string)($_POST['filling'] ?? ''));
    $flavorCustom = trim((string)($_POST['flavor_custom'] ?? ''));

    $allowedFlavors = ['chocolate', 'vanilla', 'red-velvet', 'ube', 'others'];
    $allowedVariants = ['classic', 'premium', 'sugar-free', 'others'];
    $allowedFrostings = ['buttercream', 'swiss-meringue', 'whipped-cream', 'strawberry'];
    $allowedFillings = ['vanilla', 'chocolate-ganache', 'cream-cheese', 'salted-caramel'];
    if (!in_array($flavor, $allowedFlavors, true) ||
        !in_array($flavorVariant, $allowedVariants, true) ||
        !in_array($frosting, $allowedFrostings, true) ||
        !in_array($filling, $allowedFillings, true) ||
        mb_strlen($flavorCustom) > 150) {
        respond(422, ['success' => false, 'error' => 'Check your cake base, flavor, frosting, and filling selections.']);
    }
    if (($flavor === 'others' || $flavorVariant === 'others') && $flavorCustom === '') {
        respond(422, ['success' => false, 'error' => 'Please describe your preferred flavor in the custom flavor field.']);
    }

    $designDescription = trim((string)($_POST['design_description'] ?? ''));
    if ($designDescription === '' || mb_strlen($designDescription) > 10000) {
        respond(422, ['success' => false, 'error' => 'Please enter design and decoration details.']);
    }
    $isRush = isset($_POST['is_rush']) && (string)$_POST['is_rush'] === '1' ? 1 : 0;
    $decorationRequirements = $designDescription;
    $structuralRequirements = '';

    // Complexity uses ONLY tiers, layers, overall height, and overall width.
    // The image, design description, flavor, rush selection, and availability do not affect it.
    $tierRating = [1 => 1, 2 => 3, 3 => 4, 4 => 5][$numTiers];
    $layerRating = [2 => 1, 3 => 3, 4 => 5][$numLayers];
    $heightRating = [4 => 1, 5 => 2, 6 => 3, 7 => 4, 8 => 5][$heightNumber];
    $widthRating = [4 => 1, 6 => 2, 8 => 3, 10 => 4, 12 => 5][$widthNumber];
    $complexityScore = (float)(($tierRating * 7) + ($layerRating * 5) + ($heightRating * 5) + ($widthRating * 3));
    $difficultyLevel = $complexityScore <= 39 ? 'low' : ($complexityScore <= 69 ? 'medium' : 'high');

    $uploadedFiles = [];
    if (isset($_FILES['reference_images']) && is_array($_FILES['reference_images']['name'] ?? null)) {
        $files = $_FILES['reference_images'];
        $count = count(array_filter($files['error'], static fn($e) => $e !== UPLOAD_ERR_NO_FILE));
        if ($count === 0) {
            respond(422, ['success' => false, 'error' => 'Please upload at least one reference image.']);
        }
        if ($count > 3) {
            respond(422, ['success' => false, 'error' => 'Upload no more than 3 reference images.']);
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        foreach ($files['name'] as $i => $_name) {
            $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            if ($error !== UPLOAD_ERR_OK) {
                respond(422, ['success' => false, 'error' => 'A reference image could not be uploaded.']);
            }
            $tmp = (string)($files['tmp_name'][$i] ?? '');
            $size = (int)($files['size'][$i] ?? 0);
            $mime = $tmp !== '' ? $finfo->file($tmp) : false;
            if ($size <= 0 || $size > 10 * 1024 * 1024 ||
                !isset($allowedMimeTypes[$mime]) || @getimagesize($tmp) === false) {
                respond(422, ['success' => false, 'error' => 'Reference images must be JPG, PNG, or WebP files up to 10 MB each.']);
            }
            $uploadedFiles[] = ['tmp' => $tmp, 'ext' => $allowedMimeTypes[$mime]];
        }
    } else {
    respond(422, ['success' => false, 'error' => 'Please upload at least one reference image.']);
    }

    $uploadDirectory = dirname(__DIR__) . '/uploads/order-references';
    if ($uploadedFiles && !is_dir($uploadDirectory) &&
        !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to create the reference-image directory.');
    }

    $savedPaths = [];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO `order` (
                customer_id, week_id, cake_type, occasion, design_description,
                flavor, flavor_variant, frosting, filling, flavor_custom,
                num_layers, num_tiers, cake_height, cake_width, preferred_date,
                is_rush, design_intricacy_rating, decoration_requirements,
                structural_requirements, complexity_score, difficulty_level
            ) VALUES (
                :customer_id, :week_id, :cake_type, :occasion, :design_description,
                :flavor, :flavor_variant, :frosting, :filling, :flavor_custom,
                :num_layers, :num_tiers, :cake_height, :cake_width, :preferred_date,
                :is_rush, :intricacy, :decoration_requirements,
                :structural_requirements, :complexity_score, :difficulty_level
            )'
        );
        $stmt->execute([
            'customer_id' => $customerId, 'week_id' => $weekId, 'cake_type' => $cakeType,
            'occasion' => $occasion, 'design_description' => $designDescription,
            'flavor' => $flavor, 'flavor_variant' => $flavorVariant,
            'frosting' => $frosting, 'filling' => $filling,
            'flavor_custom' => $flavorCustom !== '' ? $flavorCustom : null,
            'num_layers' => $numLayers, 'num_tiers' => $numTiers,
            'cake_height' => $cakeHeight, 'cake_width' => $cakeWidth,
            'preferred_date' => $preferredDateInput, 'is_rush' => $isRush,
            'intricacy' => $intricacy, 'decoration_requirements' => $decorationRequirements,
            'structural_requirements' => $structuralRequirements !== '' ? $structuralRequirements : null,
            'complexity_score' => $complexityScore, 'difficulty_level' => $difficultyLevel,
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $imageStmt = $pdo->prepare(
            'INSERT INTO order_reference_image (order_id, image_url) VALUES (:order_id, :image_url)'
        );
        foreach ($uploadedFiles as $file) {
            $filename = bin2hex(random_bytes(16)) . '.' . $file['ext'];
            $destination = $uploadDirectory . '/' . $filename;
            if (!move_uploaded_file($file['tmp'], $destination)) {
                throw new RuntimeException('Unable to save a reference image.');
            }
            $savedPaths[] = $destination;
            $imageStmt->execute([
                'order_id' => $orderId,
                'image_url' => 'uploads/order-references/' . $filename,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($savedPaths as $path) if (is_file($path)) @unlink($path);
        throw $e;
    }

    respond(201, [
        'success' => true,
        'order_id' => $orderId,
        'message' => 'Request submitted. Your reference number is ' . $orderId . '.',
    ]);
} catch (Throwable $e) {
    error_log('Custom cake request API error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'Unable to submit your request right now. Please try again later.', 'debug' => $e->getMessage()]);
}
