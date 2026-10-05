<?php

require "db.php";

header("Content-Type: application/json; charset=UTF-8");

/*
|--------------------------------------------------------------------------
| GET ORDER + CUSTOMER INFORMATION
|--------------------------------------------------------------------------
| Used by customerFeedback.html
|
| Example:
| feedback_data.php?order_id=2
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Invalid request method."
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| 1. GET ORDER ID
|--------------------------------------------------------------------------
*/

$orderId = isset($_GET["order_id"])
    ? (int) $_GET["order_id"]
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
| 2. GET ORDER + CUSTOMER
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        o.order_id,
        o.customer_id,
        o.status,
        o.cake_type,
        o.design_description,
        o.flavor,
        o.num_layers,
        o.num_tiers,
        o.preferred_date,

        c.full_name AS customer_name,
        c.full_name,
        c.email,
        c.contact_number

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


/*
|--------------------------------------------------------------------------
| 3. CHECK IF ORDER EXISTS
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| 4. GET ORDER DATA
|--------------------------------------------------------------------------
*/

$order = $result->fetch_assoc();


/*
|--------------------------------------------------------------------------
| 5. PREPARE CUSTOMER NAME
|--------------------------------------------------------------------------
*/

$customerName = trim($order["customer_name"] ?? "");


/*
|--------------------------------------------------------------------------
| 6. RETURN DATA
|--------------------------------------------------------------------------
*/

echo json_encode([
    "success" => true,

    "order" => [
        "order_id" => $order["order_id"],
        "customer_id" => $order["customer_id"],
        "status" => $order["status"],

        "cake_type" => $order["cake_type"],
        "design_description" => $order["design_description"],
        "flavor" => $order["flavor"],
        "num_layers" => $order["num_layers"],
        "num_tiers" => $order["num_tiers"],
        "preferred_date" => $order["preferred_date"],

        "full_name" => $customerName,
        "customer_name" => $customerName,

        "email" => $order["email"],
        "contact_number" => $order["contact_number"]
    ],

    "customer" => [
        "id" => $order["customer_id"],
        "fullName" => $customerName,
        "email" => $order["email"]
    ]
]);


$stmt->close();
$conn->close();

?>