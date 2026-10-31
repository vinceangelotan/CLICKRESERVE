<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['customer_id']) || isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Please log in.']);
    exit;
}

require_once __DIR__ . '/config/equipment_recommendations.php';

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];

$recommendations = build_equipment_recommendations(
    is_array($body['items'] ?? null) ? $body['items'] : [],
    (string) ($_SESSION['customer_skill_level'] ?? 'Beginner'),
    [
        'receiveDate' => $body['receiveDate'] ?? '',
        'receiveTime' => $body['receiveTime'] ?? '',
        'returnDate' => $body['returnDate'] ?? '',
        'returnTime' => $body['returnTime'] ?? '',
    ]
);

echo json_encode(['ok' => true, 'recommendations' => $recommendations], JSON_UNESCAPED_SLASHES);
