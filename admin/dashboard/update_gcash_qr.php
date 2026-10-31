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

require_once dirname(__DIR__, 2) . '/config/gcash_qr_repository.php';

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$accountName = trim((string) ($payload['accountName'] ?? ''));
$accountNumber = trim((string) ($payload['accountNumber'] ?? ''));
$imageDataUrl = trim((string) ($payload['imageDataUrl'] ?? ''));

if ($accountName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'GCash account name is required.']);
    exit;
}

if ($accountNumber === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'GCash account number is required.']);
    exit;
}

$current = load_gcash_qr_repository();
$next = is_array($current) ? $current : default_gcash_qr_repository_record();

$next['accountName'] = $accountName;
$next['accountNumber'] = $accountNumber;

if ($imageDataUrl !== '') {
    try {
        $next['qrImagePath'] = save_gcash_qr_image_from_data_url($imageDataUrl, dirname(__DIR__, 2));
    } catch (Throwable $error) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
        exit;
    }
}

if (trim((string) ($next['qrImagePath'] ?? '')) === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Please upload a GCash QR image.']);
    exit;
}

$next['updatedAt'] = gmdate('c');

if (!save_gcash_qr_repository($next)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to save GCash QR settings.']);
    exit;
}

$saved = load_gcash_qr_repository();

echo json_encode([
    'ok' => true,
    'message' => 'GCash QR settings updated.',
    'settings' => $saved
], JSON_UNESCAPED_SLASHES);
