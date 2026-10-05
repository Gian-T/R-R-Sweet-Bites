<?php

require "db.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Invalid request method."
    ]);

    exit;
}

$email = trim($_POST["email"] ?? "");
$password = $_POST["password"] ?? "";

if ($email === "" || $password === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Email and password are required."
    ]);

    exit;
}

/*
 * Look for the admin account in the group's `admin` table.
 */
$sql = "SELECT admin_id, full_name, email, password_hash
        FROM admin
        WHERE email = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query failed."
    ]);

    exit;
}

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password."
    ]);

    $stmt->close();
    $conn->close();
    exit;
}

$admin = $result->fetch_assoc();

/*
 * Verify the password against the password_hash
 * stored in the admin table.
 */
if (!password_verify($password, $admin["password_hash"])) {
    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Invalid email or password."
    ]);

    $stmt->close();
    $conn->close();
    exit;
}

/*
 * Login successful.
 */
echo json_encode([
    "success" => true,
    "id" => $admin["admin_id"],
    "fullName" => $admin["full_name"],
    "email" => $admin["email"]
]);

$stmt->close();
$conn->close();

?>