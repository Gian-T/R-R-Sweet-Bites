<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    require_once __DIR__ . '/../includes/db_connect.php';
    $pdo = db();

    $statement = $pdo->query(   
        'SELECT gallery_id, image_url, title, description, category, occasion
         FROM gallery_item
         ORDER BY created_at DESC, gallery_id DESC'
    );

    echo json_encode(
        ['success' => true, 'items' => $statement->fetchAll()],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    error_log('Gallery API error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
}
