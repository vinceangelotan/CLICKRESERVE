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

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$slot = (int) ($payload['slot'] ?? 0);
if ($slot < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Slot must be 1 or greater.']);
    exit;
}

$imageDataUrl = trim((string) ($payload['imageDataUrl'] ?? ''));
if ($imageDataUrl === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Image data is required']);
    exit;
}

$projectRoot = dirname(__DIR__, 2);

try {
    $targetPath = save_promo_banner_image_from_data_url($slot, $imageDataUrl, $projectRoot);

    echo json_encode([
        'ok' => true,
        'slot' => $slot,
        'targetPath' => $targetPath
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => $error->getMessage()
    ]);
}
