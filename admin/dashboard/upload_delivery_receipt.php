<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function upload_delivery_receipt_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$isAdminSession = isset($_SESSION['user_id']) && !isset($_SESSION['customer_id']);
$isStaffSession = isset($_SESSION['staff_id']) && !isset($_SESSION['customer_id']);

if (!$isAdminSession && !$isStaffSession) {
    upload_delivery_receipt_respond(403, [
        'ok' => false,
        'message' => 'Unauthorized',
    ]);
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    upload_delivery_receipt_respond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
}

require_once dirname(__DIR__, 2) . '/config/customer_orders_repository.php';

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (isset($payload['order']) && is_array($payload['order'])) {
    $payload = $payload['order'];
}

$orderId = trim((string) ($payload['orderId'] ?? ($payload['order_id'] ?? '')));
$imageDataUrl = trim((string) ($payload['imageDataUrl'] ?? ($payload['image_data_url'] ?? '')));
$deliveryDirection = trim((string) ($payload['direction']
    ?? ($payload['deliveryDirection']
    ?? ($payload['delivery_direction']
    ?? ''))));
$deliveryReference = trim((string) ($payload['deliveryReference']
    ?? ($payload['delivery_reference']
    ?? ($payload['reference'] ?? ''))));
$deliveryNotes = trim((string) ($payload['deliveryNotes']
    ?? ($payload['delivery_notes']
    ?? ($payload['notes'] ?? ''))));

$hasDeliveryReferencePayload = array_key_exists('deliveryReference', $payload)
    || array_key_exists('delivery_reference', $payload)
    || array_key_exists('reference', $payload);
$hasDeliveryNotesPayload = array_key_exists('deliveryNotes', $payload)
    || array_key_exists('delivery_notes', $payload)
    || array_key_exists('notes', $payload);

if ($orderId === '') {
    upload_delivery_receipt_respond(422, [
        'ok' => false,
        'message' => 'Order ID is required.',
    ]);
}

if ($imageDataUrl === '') {
    upload_delivery_receipt_respond(422, [
        'ok' => false,
        'message' => 'Please provide a delivery receipt image.',
    ]);
}

if ($deliveryDirection !== '' && normalize_customer_order_delivery_leg($deliveryDirection) !== 'receive') {
    upload_delivery_receipt_respond(422, [
        'ok' => false,
        'message' => 'Admin delivery uploads only support the receive leg.',
    ]);
}

$options = [];

if ($hasDeliveryReferencePayload) {
    $options['delivery_reference'] = $deliveryReference;
}

if ($hasDeliveryNotesPayload) {
    $options['delivery_notes'] = $deliveryNotes;
}

try {
    $updatedOrder = upload_customer_order_delivery_receipt_for_admin(
        $orderId,
        $imageDataUrl,
        dirname(__DIR__, 2),
        $options
    );
} catch (Throwable $error) {
    upload_delivery_receipt_respond(500, [
        'ok' => false,
        'message' => 'Delivery receipt upload failed due to a technical issue. Please try again.',
    ]);
}

if ($updatedOrder === null) {
    upload_delivery_receipt_respond(409, [
        'ok' => false,
        'message' => 'This booking is not eligible for receive-delivery receipt upload.',
    ]);
}

upload_delivery_receipt_respond(200, [
    'ok' => true,
    'message' => 'Delivery receipt uploaded successfully.',
    'order' => $updatedOrder,
]);
