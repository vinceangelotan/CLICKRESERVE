<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || isset($_SESSION['customer_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

require dirname(__DIR__, 2) . '/config/products_repository.php';

$products = load_products_repository();
$projectRoot = dirname(__DIR__, 2);

try {
    $result = create_product_record($products, $projectRoot);

    if (!save_products_repository($result['products'])) {
        throw new RuntimeException('Unable to save new product.');
    }

    echo json_encode([
        'ok' => true,
        'newKey' => $result['newKey'],
        'newProduct' => $result['newProduct']
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => $error->getMessage()
    ]);
}
