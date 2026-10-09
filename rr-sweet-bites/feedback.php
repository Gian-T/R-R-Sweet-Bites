<?php

require "db.php";

header("Content-Type: application/json; charset=UTF-8");

/*
|--------------------------------------------------------------------------
| BASIC SETTINGS
|--------------------------------------------------------------------------
*/

$uploadDirectory = __DIR__ . "/uploads/feedback/";
$uploadDatabasePath = "uploads/feedback/";

/*
|--------------------------------------------------------------------------
| CREATE UPLOAD FOLDER IF IT DOES NOT EXIST
|--------------------------------------------------------------------------
*/

if (!is_dir($uploadDirectory)) {
    mkdir($uploadDirectory, 0777, true);
}

/*
|--------------------------------------------------------------------------
| GET ORDER INFORMATION
|--------------------------------------------------------------------------
|
| The Customer Feedback page must send the order_id.
|
*/

$orderId = isset($_POST["order_id"])
    ? (int) $_POST["order_id"]
    : 0;

if ($orderId <= 0) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid order ID."
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| GET ORDER + CUSTOMER
|--------------------------------------------------------------------------
|
| Customer name and email come from the database.
| They are NOT hard-coded.
|
*/

$sql = "
    SELECT
        o.order_id,
        o.customer_id,
        o.status,
        c.full_name,
        c.email
    FROM `order` o
    INNER JOIN customer c
        ON o.customer_id = c.customer_id
    WHERE o.order_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query failed."
    ]);

    exit;
}

$stmt->bind_param("i", $orderId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Order not found."
    ]);

    $stmt->close();
    $conn->close();

    exit;
}

$order = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| ONLY COMPLETED ORDERS CAN SUBMIT FEEDBACK
|--------------------------------------------------------------------------
*/

if ($order["status"] !== "completed") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Feedback can only be submitted for completed orders."
    ]);

    $conn->close();

    exit;
}

/*
|--------------------------------------------------------------------------
| GET OVERALL RATING
|--------------------------------------------------------------------------
*/

$rating = isset($_POST["rating"])
    ? (int) $_POST["rating"]
    : 0;

if ($rating < 1 || $rating > 5) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Overall rating must be between 1 and 5."
    ]);

    $conn->close();

    exit;
}

/*
|--------------------------------------------------------------------------
| GET INDIVIDUAL RATINGS
|--------------------------------------------------------------------------
*/

$designRating = isset($_POST["design_rating"])
    ? (int) $_POST["design_rating"]
    : 0;

$flavorRating = isset($_POST["flavor_rating"])
    ? (int) $_POST["flavor_rating"]
    : 0;

$deliveryRating = isset($_POST["delivery_rating"])
    ? (int) $_POST["delivery_rating"]
    : 0;

$coordinationRating = isset($_POST["coordination_rating"])
    ? (int) $_POST["coordination_rating"]
    : 0;

$individualRatings = [
    "Design & Visual Fidelity" => $designRating,
    "Flavor & Texture Balance" => $flavorRating,
    "Cold-Chain Delivery & Temperature" => $deliveryRating,
    "Chef Coordination & Care" => $coordinationRating
];

foreach ($individualRatings as $name => $value) {

    if ($value < 1 || $value > 5) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => $name . " rating must be between 1 and 5."
        ]);

        $conn->close();

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| GET WRITTEN FEEDBACK
|--------------------------------------------------------------------------
*/

$feedbackText = trim($_POST["feedback_text"] ?? "");

if (strlen($feedbackText) > 1000) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Feedback is too long. Maximum is 1000 characters."
    ]);

    $conn->close();

    exit;
}

/*
|--------------------------------------------------------------------------
| GET GALLERY CONSENT
|--------------------------------------------------------------------------
*/

$galleryConsent = isset($_POST["gallery_consent"])
    ? (int) $_POST["gallery_consent"]
    : 0;

$galleryConsent = ($galleryConsent === 1) ? 1 : 0;

/*
|--------------------------------------------------------------------------
| GET DISPLAY PREFERENCE
|--------------------------------------------------------------------------
|
| Allowed values:
| - named
| - anonymous
|
*/

$displayPreference = $_POST["display_preference"] ?? "anonymous";

if (!in_array($displayPreference, ["named", "anonymous"], true)) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid display preference."
    ]);

    $conn->close();

    exit;
}

/*
|--------------------------------------------------------------------------
| GET HIGHLIGHT TAGS
|--------------------------------------------------------------------------
|
| The HTML should send:
|
| highlights[]
|
| Example:
| highlights[] = "Stunning Centerpiece ✦"
| highlights[] = "Guests Loved It ♥"
|
*/

$highlights = $_POST["highlights"] ?? [];

if (!is_array($highlights)) {
    $highlights = [];
}

/*
|--------------------------------------------------------------------------
| ALLOWED HIGHLIGHTS
|--------------------------------------------------------------------------
*/

$allowedHighlights = [
    "Stunning Centerpiece ✦",
    "Pristine Delivery Condition ✦",
    "Perfect Sweetness Balance ✦",
    "Courteous Courier 🛵",
    "Guests Loved It ♥",
    "Rich Moist Layers 🍰",
    "Flawless Floral Piping 🌸",
    "Accurate Color Palette 🎨"
];

/*
|--------------------------------------------------------------------------
| REMOVE INVALID / DUPLICATE HIGHLIGHTS
|--------------------------------------------------------------------------
*/

$cleanHighlights = [];

foreach ($highlights as $highlight) {

    $highlight = trim($highlight);

    if (
        in_array($highlight, $allowedHighlights, true)
        && !in_array($highlight, $cleanHighlights, true)
    ) {
        $cleanHighlights[] = $highlight;
    }
}

/*
|--------------------------------------------------------------------------
| CHECK IF FEEDBACK ALREADY EXISTS
|--------------------------------------------------------------------------
*/

$checkSql = "
    SELECT feedback_id
    FROM feedback
    WHERE order_id = ?
    LIMIT 1
";

$checkStmt = $conn->prepare($checkSql);

if (!$checkStmt) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to check existing feedback."
    ]);

    $conn->close();

    exit;
}

$checkStmt->bind_param("i", $orderId);
$checkStmt->execute();

$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {

    http_response_code(409);

    echo json_encode([
        "success" => false,
        "message" => "Feedback has already been submitted for this order."
    ]);

    $checkStmt->close();
    $conn->close();

    exit;
}

$checkStmt->close();

/*
|--------------------------------------------------------------------------
| CHECK UPLOADED FILES
|--------------------------------------------------------------------------
*/

$uploadedFiles = [];

if (isset($_FILES["media"])) {

    if (isset($_FILES["media"]["name"]) && is_array($_FILES["media"]["name"])) {

        $fileCount = count($_FILES["media"]["name"]);

        if ($fileCount > 5) {

            http_response_code(400);

            echo json_encode([
                "success" => false,
                "message" => "You can upload a maximum of 5 photos or videos."
            ]);

            $conn->close();

            exit;
        }

        /*
        |--------------------------------------------------------------------------
        | ALLOWED MIME TYPES
        |--------------------------------------------------------------------------
        */

        $allowedMimeTypes = [

            // Images
            "image/jpeg" => "jpg",
            "image/png"  => "png",
            "image/gif"  => "gif",
            "image/webp" => "webp",

            // Videos
            "video/mp4"  => "mp4",
            "video/webm" => "webm",
            "video/quicktime" => "mov"
        ];

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        for ($i = 0; $i < $fileCount; $i++) {

            $tmpName = $_FILES["media"]["tmp_name"][$i];
            $originalName = $_FILES["media"]["name"][$i];
            $error = $_FILES["media"]["error"][$i];
            $size = $_FILES["media"]["size"][$i];

            /*
            |--------------------------------------------------------------------------
            | SKIP EMPTY FILE INPUTS
            |--------------------------------------------------------------------------
            */

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | CHECK UPLOAD ERROR
            |--------------------------------------------------------------------------
            */

            if ($error !== UPLOAD_ERR_OK) {

                http_response_code(400);

                echo json_encode([
                    "success" => false,
                    "message" => "One of the uploaded files could not be uploaded."
                ]);

                $conn->close();

                exit;
            }

            /*
            |--------------------------------------------------------------------------
            | MAX FILE SIZE
            |--------------------------------------------------------------------------
            |
            | 10 MB per file
            |
            */

            if ($size > 10 * 1024 * 1024) {

                http_response_code(400);

                echo json_encode([
                    "success" => false,
                    "message" => "Each photo or video must be 10 MB or smaller."
                ]);

                $conn->close();

                exit;
            }

            /*
            |--------------------------------------------------------------------------
            | DETERMINE REAL FILE TYPE
            |--------------------------------------------------------------------------
            */

            $mimeType = $finfo->file($tmpName);

            if (!isset($allowedMimeTypes[$mimeType])) {

                http_response_code(400);

                echo json_encode([
                    "success" => false,
                    "message" => "One of the uploaded files is not a supported photo or video."
                ]);

                $conn->close();

                exit;
            }

            $extension = $allowedMimeTypes[$mimeType];

            /*
            |--------------------------------------------------------------------------
            | DETERMINE MEDIA TYPE
            |--------------------------------------------------------------------------
            */

            if (strpos($mimeType, "image/") === 0) {
                $mediaType = "image";
            } else {
                $mediaType = "video";
            }

            /*
            |--------------------------------------------------------------------------
            | CREATE A SAFE UNIQUE FILE NAME
            |--------------------------------------------------------------------------
            */

            $newFileName = uniqid("feedback_", true) . "." . $extension;

            $destination = $uploadDirectory . $newFileName;

            /*
            |--------------------------------------------------------------------------
            | MOVE FILE
            |--------------------------------------------------------------------------
            */

            if (!move_uploaded_file($tmpName, $destination)) {

                http_response_code(500);

                echo json_encode([
                    "success" => false,
                    "message" => "Unable to save one of the uploaded files."
                ]);

                $conn->close();

                exit;
            }

            /*
            |--------------------------------------------------------------------------
            | REMEMBER FILE INFORMATION
            |--------------------------------------------------------------------------
            */

            $uploadedFiles[] = [
                "file_path" => $uploadDatabasePath . $newFileName,
                "media_type" => $mediaType
            ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| START DATABASE TRANSACTION
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | INSERT FEEDBACK
    |--------------------------------------------------------------------------
    */

    $insertSql = "
        INSERT INTO feedback (
            order_id,
            customer_id,
            rating,
            design_rating,
            flavor_rating,
            delivery_rating,
            coordination_rating,
            feedback_text,
            gallery_consent,
            display_preference
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $insertStmt = $conn->prepare($insertSql);

    if (!$insertStmt) {
        throw new Exception("Unable to prepare feedback submission.");
    }

    $insertStmt->bind_param(
        "iiiiiisiss",
        $order["order_id"],
        $order["customer_id"],
        $rating,
        $designRating,
        $flavorRating,
        $deliveryRating,
        $coordinationRating,
        $feedbackText,
        $galleryConsent,
        $displayPreference
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Unable to save feedback.");
    }

    $feedbackId = $insertStmt->insert_id;

    $insertStmt->close();

    /*
    |--------------------------------------------------------------------------
    | INSERT HIGHLIGHT TAGS
    |--------------------------------------------------------------------------
    */

    if (count($cleanHighlights) > 0) {

        $highlightSql = "
            INSERT INTO feedback_highlight (
                feedback_id,
                highlight_tag
            )
            VALUES (?, ?)
        ";

        $highlightStmt = $conn->prepare($highlightSql);

        if (!$highlightStmt) {
            throw new Exception("Unable to prepare highlight tags.");
        }

        foreach ($cleanHighlights as $highlight) {

            $highlightStmt->bind_param(
                "is",
                $feedbackId,
                $highlight
            );

            if (!$highlightStmt->execute()) {
                throw new Exception("Unable to save highlight tags.");
            }
        }

        $highlightStmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT MEDIA RECORDS
    |--------------------------------------------------------------------------
    */

    if (count($uploadedFiles) > 0) {

        $mediaSql = "
            INSERT INTO feedback_media (
                feedback_id,
                file_path,
                media_type
            )
            VALUES (?, ?, ?)
        ";

        $mediaStmt = $conn->prepare($mediaSql);

        if (!$mediaStmt) {
            throw new Exception(
                "Unable to prepare uploaded media records. Make sure the feedback_media table exists."
            );
        }

        foreach ($uploadedFiles as $file) {

            $mediaStmt->bind_param(
                "iss",
                $feedbackId,
                $file["file_path"],
                $file["media_type"]
            );

            if (!$mediaStmt->execute()) {
                throw new Exception("Unable to save uploaded media.");
            }
        }

        $mediaStmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | EVERYTHING SUCCESSFUL
    |--------------------------------------------------------------------------
    */

    $conn->commit();

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        "success" => true,
        "message" => "Feedback submitted successfully.",
        "feedback_id" => $feedbackId,
        "order_id" => $order["order_id"],

        "customer" => [
            "id" => $order["customer_id"],
            "fullName" => $order["full_name"],
            "email" => $order["email"]
        ],

        "rating" => $rating,

        "ratings" => [
            "design" => $designRating,
            "flavor" => $flavorRating,
            "delivery" => $deliveryRating,
            "coordination" => $coordinationRating
        ],

        "highlights" => $cleanHighlights,

        "galleryConsent" => $galleryConsent,

        "displayPreference" => $displayPreference,

        "mediaCount" => count($uploadedFiles)
    ]);

} catch (Exception $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK DATABASE CHANGES
    |--------------------------------------------------------------------------
    */

    $conn->rollback();

    /*
    |--------------------------------------------------------------------------
    | REMOVE UPLOADED FILES IF DATABASE SAVE FAILED
    |--------------------------------------------------------------------------
    */

    foreach ($uploadedFiles as $file) {

        $fileToDelete = __DIR__ . "/" . $file["file_path"];

        if (file_exists($fileToDelete)) {
            unlink($fileToDelete);
        }
    }

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}

$conn->close();

?>