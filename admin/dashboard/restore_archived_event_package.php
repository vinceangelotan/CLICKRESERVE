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

require dirname(__DIR__, 2) . '/config/event_packages_repository.php';

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$packageKey = normalize_event_package_key((string) ($payload['packageKey'] ?? ''));
if ($packageKey === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Package key is required']);
    exit;
}

$eventPackages = load_event_packages_repository();

if (!isset($eventPackages[$packageKey]) || !is_array($eventPackages[$packageKey])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Event package was not found']);
    exit;
}

if (empty($eventPackages[$packageKey]['archived'])) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'Event package is already active']);
    exit;
}

$eventPackages[$packageKey]['archived'] = false;
$eventPackages[$packageKey]['archivedAt'] = '';

if (!save_event_packages_repository($eventPackages)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to restore event package']);
    exit;
}

echo json_encode([
    'ok' => true,
    'restoredPackage' => [
        'packageKey' => $packageKey,
        'title' => (string) ($eventPackages[$packageKey]['title'] ?? strtoupper(str_replace('-', ' ', $packageKey)))
    ]
]);
