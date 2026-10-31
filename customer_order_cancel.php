<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function customer_order_cancel_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    customer_order_cancel_respond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['customer_id']) || isset($_SESSION['user_id'])) {
    customer_order_cancel_respond(401, [
        'ok' => false,
        'message' => 'Please log in to manage bookings.',
    ]);
}

require_once __DIR__ . '/config/customer_orders_repository.php';

$rawBody = file_get_contents('php://input');
$decodedBody = json_decode((string) $rawBody, true);

$payload = is_array($decodedBody) ? $decodedBody : [];

if (isset($payload['order']) && is_array($payload['order'])) {
    $payload = $payload['order'];
}

$orderId = trim((string) ($payload['orderId'] ?? ($_POST['order_id'] ?? '')));
$cancelReason = trim((string) ($payload['reason'] ?? ($_POST['reason'] ?? '')));

if ($orderId === '') {
    customer_order_cancel_respond(422, [
        'ok' => false,
        'message' => 'Order ID is required.',
    ]);
}

if ($cancelReason === '') {
    customer_order_cancel_respond(422, [
        'ok' => false,
        'message' => 'Please provide a cancellation reason.',
    ]);
}

$customerId = (string) ($_SESSION['customer_id'] ?? '');
$updatedOrder = cancel_customer_order_for_customer($customerId, $orderId, $cancelReason);

if ($updatedOrder === null) {
    customer_order_cancel_respond(409, [
        'ok' => false,
        'message' => 'This booking can no longer be canceled.',
    ]);
}

$updatedStatusToken = normalize_customer_order_status_token($updatedOrder['status'] ?? 'canceled');
$successMessage = $updatedStatusToken === 'awaiting-refund'
    ? 'Booking canceled. Your refund is now being processed.'
    : 'Booking canceled successfully.';

customer_order_cancel_respond(200, [
    'ok' => true,
    'message' => $successMessage,
    'order' => $updatedOrder,
]);
