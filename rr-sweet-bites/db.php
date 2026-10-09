<?php

$host = "localhost";
$username = "rr_app";
$password = "RRapp@12345";
$database = "rr_sweet_bites";
$port = 3307;

$conn = new mysqli(
    $host,
    $username,
    $password,
    $database,
    $port
);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>