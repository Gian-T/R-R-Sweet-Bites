<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function portalResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    portalResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}
if (empty($_SESSION['customer_id'])) {
    portalResponse(['success' => false, 'message' => 'Please sign in again.'], 401);
}
$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['customer_csrf']) || !is_string($token) || !hash_equals($_SESSION['customer_csrf'], $token)) {
    portalResponse(['success' => false, 'message' => 'Your session expired. Refresh and try again.'], 400);
}

$action = (string) ($_POST['action'] ?? '');
$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
if (!$orderId || $orderId < 1) {
    portalResponse(['success' => false, 'message' => 'Choose a valid order.'], 422);
}

try {
    require_once __DIR__ . '/../../VS-CODE-R&R/includes/db_connect.php';
    $ownerStatement = $pdo->prepare('SELECT order_id, order_status FROM customer_portal_orders WHERE order_id = :order_id AND customer_id = :customer_id');
    $ownerStatement->execute(['order_id' => $orderId, 'customer_id' => $_SESSION['customer_id']]);
    $order = $ownerStatement->fetch();
    if (!$order) {
        portalResponse(['success' => false, 'message' => 'That order was not found in your account.'], 404);
    }

    if ($action === 'fulfillment') {
        $method = (string) ($_POST['fulfillment_method'] ?? '');
        $address = trim((string) ($_POST['delivery_address'] ?? ''));
        $city = trim((string) ($_POST['delivery_city'] ?? ''));
        $postal = trim((string) ($_POST['delivery_postal_code'] ?? ''));
        if (!in_array($method, ['pickup', 'delivery'], true) || ($method === 'delivery' && ($address === '' || $city === ''))) {
            portalResponse(['success' => false, 'message' => 'Select a method and enter the delivery address and city when choosing delivery.'], 422);
        }
        $statement = $pdo->prepare('UPDATE customer_portal_orders SET fulfillment_method = :method, delivery_address = :address, delivery_city = :city, delivery_postal_code = :postal WHERE order_id = :order_id AND customer_id = :customer_id');
        $statement->execute([
            'method' => $method,
            'address' => $method === 'delivery' ? $address : null,
            'city' => $method === 'delivery' ? $city : null,
            'postal' => $method === 'delivery' ? $postal : null,
            'order_id' => $orderId,
            'customer_id' => $_SESSION['customer_id'],
        ]);
        portalResponse(['success' => true, 'message' => 'Your fulfillment preference has been saved. The bakery will confirm availability.']);
    }

    if ($action === 'feedback') {
        $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
        $review = trim((string) ($_POST['review_text'] ?? ''));
        if (!$rating || $rating < 1 || $rating > 5 || $review === '' || mb_strlen($review) > 3000) {
            portalResponse(['success' => false, 'message' => 'Choose a rating and enter a review (up to 3,000 characters).'], 422);
        }
        $statement = $pdo->prepare('INSERT INTO customer_order_feedback (order_id, customer_id, rating, review_text, would_recommend) VALUES (:order_id, :customer_id, :rating, :review_text, :would_recommend)');
        try {
            $statement->execute([
                'order_id' => $orderId,
                'customer_id' => $_SESSION['customer_id'],
                'rating' => $rating,
                'review_text' => $review,
                'would_recommend' => isset($_POST['would_recommend']) ? 1 : 0,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                portalResponse(['success' => false, 'message' => 'A review has already been submitted for this order.'], 409);
            }
            throw $exception;
        }
        portalResponse(['success' => true, 'message' => 'Thank you. Your review has been submitted.']);
    }

    if ($action === 'cancel') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 1000) {
            portalResponse(['success' => false, 'message' => 'Enter a cancellation reason (up to 1,000 characters).'], 422);
        }
        if (in_array($order['order_status'], ['delivered', 'cancelled'], true)) {
            portalResponse(['success' => false, 'message' => 'This order can no longer be cancelled through the customer page.'], 409);
        }
        $statement = $pdo->prepare('INSERT INTO customer_order_cancellation_requests (order_id, customer_id, reason) VALUES (:order_id, :customer_id, :reason)');
        $statement->execute(['order_id' => $orderId, 'customer_id' => $_SESSION['customer_id'], 'reason' => $reason]);
        portalResponse(['success' => true, 'message' => 'Your cancellation request was sent for bakery review. The order remains active until confirmed.']);
    }

    portalResponse(['success' => false, 'message' => 'Unknown customer action.'], 400);
} catch (Throwable $exception) {
    error_log('Customer portal action error: ' . $exception->getMessage());
    portalResponse(['success' => false, 'message' => 'Could not save that change. Confirm the customer SQL is imported and try again.'], 503);
}
