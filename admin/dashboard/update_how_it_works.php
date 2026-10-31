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

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$slot = (int) ($payload['slot'] ?? 0);
if ($slot < 1 || $slot > 4) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Slot must be between 1 and 4.']);
    exit;
}

$imageDataUrl = trim((string) ($payload['imageDataUrl'] ?? ''));
if (!preg_match('/^data:image\/png;base64,(.+)$/i', $imageDataUrl, $matches)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid image payload. Please crop and save again.']);
    exit;
}

$binary = base64_decode($matches[1], true);
if ($binary === false) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid base64 image payload.']);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$targetDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'how_it_works';
if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to access target image directory.']);
    exit;
}

$filename = $slot . '.png';
$absolutePath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;
if (file_put_contents($absolutePath, $binary, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to save image.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'slot' => $slot,
    'relativePath' => 'assets/how_it_works/' . $filename
]);
