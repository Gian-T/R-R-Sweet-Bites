<?php

declare(strict_types=1);
ini_set('display_errors', '0');   // never leak HTML into JSON
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');


function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['success' => false, 'error' => 'Method not allowed']);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../includes/db_connect.php'; 

$customerId = filter_var($_SESSION['customer_id'] ?? CUSTOMER_ID, FILTER_VALIDATE_INT);

$customerId = filter_var($_SESSION['customer_id'] ?? null, FILTER_VALIDATE_INT);
if (!$customerId) {
    respond(401, ['success' => false, 'error' => 'Please log in before submitting a cake request.']);
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
$submittedToken = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_ok()) {
    respond(403, ['success' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
}

try {
    require_once __DIR__ . '/../includes/db_connect.php';
    $pdo = db();

    $customerStatement = $pdo->prepare( //error
        'SELECT full_name, email, contact_number FROM customer WHERE customer_id = :customer_id'
    );
    $customerStatement->execute(['customer_id' => $customerId]);
    $customer = $customerStatement->fetch();
    if (!$customer) {
        respond(401, ['success' => false, 'error' => 'Your customer account could not be verified. Please log in again.']);
    }

    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    if (
        $customerName !== $customer['full_name']
        || strtolower($email) !== strtolower((string) $customer['email'])
        || $phone !== $customer['contact_number']
    ) {
        respond(422, ['success' => false, 'error' => 'Contact details must match your customer profile.']);
    }

    $cakeType = (string) ($_POST['cake_type'] ?? '');
    $validCakeTypes = ['cake', 'cupcake', 'number_shaped_cake'];
    if (!in_array($cakeType, $validCakeTypes, true)) {
        respond(422, ['success' => false, 'error' => 'Choose a valid cake type.']);
    }

    $numTiers = filter_var($_POST['num_tiers'] ?? null, FILTER_VALIDATE_INT);
    $numLayers = filter_var($_POST['num_layers'] ?? null, FILTER_VALIDATE_INT);
    $intricacy = filter_var($_POST['design_intricacy_rating'] ?? null, FILTER_VALIDATE_INT);
    if (!$numTiers || $numTiers > 10 || !$numLayers || $numLayers > 10 || !$intricacy || $intricacy < 1 || $intricacy > 5) {
        respond(422, ['success' => false, 'error' => 'Check the selected tiers, layers, and design complexity.']);
    }

    $preferredDateInput = (string) ($_POST['preferred_date'] ?? '');
    $preferredDate = DateTimeImmutable::createFromFormat('!Y-m-d', $preferredDateInput);
    if (!$preferredDate || $preferredDate->format('Y-m-d') !== $preferredDateInput || $preferredDateInput < date('Y-m-d')) {
        respond(422, ['success' => false, 'error' => 'Choose a valid date that is today or later.']);
    }

    $baseFlavor = trim((string) ($_POST['flavor'] ?? ''));
    $filling = trim((string) ($_POST['filling'] ?? ''));
    $frosting = trim((string) ($_POST['frosting'] ?? ''));
    if ($baseFlavor === '' || $filling === '' || $frosting === '') {
        respond(422, ['success' => false, 'error' => 'Select a cake flavor, filling, and frosting.']);
    }
    $flavor = sprintf('Base: %s; Filling: %s; Frosting: %s', $baseFlavor, $filling, $frosting);
    if (strlen($flavor) > 150) {
        respond(422, ['success' => false, 'error' => 'The selected flavor details are too long.']);
    }

    $designDescription = trim((string) ($_POST['design_description'] ?? ''));
    $structuralRequirements = trim((string) ($_POST['structural_requirements'] ?? ''));
    if ($designDescription === '' || strlen($designDescription) > 10000 || strlen($structuralRequirements) > 2000) {
        respond(422, ['success' => false, 'error' => 'Add design details and keep each description within its limit.']);
    }

    $isRush = ($_POST['is_rush'] ?? '') === '1' ? 1 : 0;
    $decorationRequirements = $designDescription;

    $complexityScore = round(($intricacy * 2) + ($numLayers * 1.5) + ($numTiers * 2), 2);
    $difficultyLevel = $complexityScore <= 10 ? 'low' : ($complexityScore <= 18 ? 'medium' : 'high');

    $uploadedFiles = [];
    if (isset($_FILES['reference_images'])) {
        $files = $_FILES['reference_images'];
        if (!is_array($files['name'] ?? null)) {
            respond(422, ['success' => false, 'error' => 'Invalid image upload.']);
        }

        $fileCount = count(array_filter($files['error'], static fn ($error): bool => $error !== UPLOAD_ERR_NO_FILE));
        if ($fileCount > 3) {
            respond(422, ['success' => false, 'error' => 'Upload no more than 3 reference images.']);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        foreach ($files['name'] as $index => $originalName) {
            $uploadError = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($uploadError === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($uploadError !== UPLOAD_ERR_OK) {
                respond(422, ['success' => false, 'error' => 'A reference image could not be uploaded.']);
            }

            $temporaryPath = $files['tmp_name'][$index] ?? '';
            $size = (int) ($files['size'][$index] ?? 0);
            $mimeType = $temporaryPath !== '' ? $finfo->file($temporaryPath) : false;
            if ($size <= 0 || $size > 10 * 1024 * 1024 || !isset($allowedMimeTypes[$mimeType]) || @getimagesize($temporaryPath) === false) {
                respond(422, ['success' => false, 'error' => 'Reference images must be JPG, PNG, or WebP files up to 10 MB each.']);
            }

            $uploadedFiles[] = [
                'temporary_path' => $temporaryPath,
                'extension' => $allowedMimeTypes[$mimeType],
            ];
        }
    }

    $uploadDirectory = dirname(__DIR__) . '/uploads/order-references';
    if ($uploadedFiles !== [] && !is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to create the reference-image directory.');
    }

    $savedImagePaths = [];
    $pdo->beginTransaction(); //error
    try {
        $orderStatement = $pdo->prepare( //error
            'INSERT INTO `order` (
                customer_id, cake_type, design_description, flavor, num_layers, num_tiers,
                preferred_date, is_rush, design_intricacy_rating, decoration_requirements,
                structural_requirements, complexity_score, difficulty_level
            ) VALUES (
                :customer_id, :cake_type, :design_description, :flavor, :num_layers, :num_tiers,
                :preferred_date, :is_rush, :design_intricacy_rating, :decoration_requirements,
                :structural_requirements, :complexity_score, :difficulty_level
            )'
        );
        $orderStatement->execute([
            'customer_id' => $customerId,
            'cake_type' => $cakeType,
            'design_description' => $designDescription,
            'flavor' => $flavor,
            'num_layers' => $numLayers,
            'num_tiers' => $numTiers,
            'preferred_date' => $preferredDateInput,
            'is_rush' => $isRush,
            'design_intricacy_rating' => $intricacy,
            'decoration_requirements' => $decorationRequirements,
            'structural_requirements' => $structuralRequirements !== '' ? $structuralRequirements : null,
            'complexity_score' => $complexityScore,
            'difficulty_level' => $difficultyLevel,
        ]);
        $orderId = (int) $pdo->lastInsertId(); //error

        $imageStatement = $pdo->prepare( //error
            'INSERT INTO order_reference_image (order_id, image_url) VALUES (:order_id, :image_url)'
        );
        foreach ($uploadedFiles as $file) {
            $fileName = bin2hex(random_bytes(16)) . '.' . $file['extension'];
            $destination = $uploadDirectory . '/' . $fileName;
            if (!move_uploaded_file($file['temporary_path'], $destination)) {
                throw new RuntimeException('Unable to save a reference image.');
            }
            $savedImagePaths[] = $destination;
            $imageStatement->execute([
                'order_id' => $orderId,
                'image_url' => 'uploads/order-references/' . $fileName,
            ]);
        }

        $pdo->commit(); //error
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { //error
            $pdo->rollBack(); //error
        }
        foreach ($savedImagePaths as $savedImagePath) {
            if (is_file($savedImagePath)) {
                unlink($savedImagePath);
            }
        }
        throw $exception;
    }

    respond(201, [
        'success' => true,
        'order_id' => $orderId,
        'message' => 'Request submitted. Your reference number is ' . $orderId . '.',
    ]);
} catch (Throwable $exception) {
    error_log('Custom cake request API error: ' . $exception->getMessage());
    respond(500, ['success' => false, 'error' => 'Unable to submit your request right now. Please try again later.']);
}
