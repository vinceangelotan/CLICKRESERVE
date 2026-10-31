<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function customer_order_upload_delivery_receipt_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    customer_order_upload_delivery_receipt_respond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['customer_id']) || isset($_SESSION['user_id'])) {
    customer_order_upload_delivery_receipt_respond(401, [
        'ok' => false,
        'message' => 'Please log in to upload delivery receipts.',
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
$imageDataUrl = trim((string) ($payload['imageDataUrl'] ?? ($_POST['image_data_url'] ?? '')));
$deliveryDirection = trim((string) (
    $payload['direction']
    ?? $payload['deliveryDirection']
    ?? $payload['delivery_direction']
    ?? $_POST['direction']
    ?? $_POST['deliveryDirection']
    ?? $_POST['delivery_direction']
    ?? ''
));
$deliveryReference = trim((string) (
    $payload['deliveryReference']
    ?? $payload['delivery_reference']
    ?? $payload['reference']
    ?? ''
));
$deliveryNotes = trim((string) (
    $payload['deliveryNotes']
    ?? $payload['delivery_notes']
    ?? $payload['notes']
    ?? ''
));

if ($deliveryReference === '') {
    $deliveryReference = trim((string) (
        $_POST['delivery_reference']
        ?? $_POST['deliveryReference']
        ?? $_POST['reference']
        ?? ''
    ));
}

if ($deliveryNotes === '') {
    $deliveryNotes = trim((string) (
        $_POST['delivery_notes']
        ?? $_POST['deliveryNotes']
        ?? $_POST['notes']
        ?? ''
    ));
}

$hasDeliveryReferencePayload = array_key_exists('deliveryReference', $payload)
    || array_key_exists('delivery_reference', $payload)
    || array_key_exists('reference', $payload)
    || array_key_exists('delivery_reference', $_POST)
    || array_key_exists('deliveryReference', $_POST)
    || array_key_exists('reference', $_POST);
$hasDeliveryNotesPayload = array_key_exists('deliveryNotes', $payload)
    || array_key_exists('delivery_notes', $payload)
    || array_key_exists('notes', $payload)
    || array_key_exists('delivery_notes', $_POST)
    || array_key_exists('deliveryNotes', $_POST)
    || array_key_exists('notes', $_POST);

if ($orderId === '') {
    customer_order_upload_delivery_receipt_respond(422, [
        'ok' => false,
        'message' => 'Order ID is required.',
    ]);
}

if ($imageDataUrl === '') {
    customer_order_upload_delivery_receipt_respond(422, [
        'ok' => false,
        'message' => 'Please select a delivery receipt image to upload.',
    ]);
}

if ($deliveryDirection !== '' && normalize_customer_order_delivery_leg($deliveryDirection) !== 'return') {
    customer_order_upload_delivery_receipt_respond(422, [
        'ok' => false,
        'message' => 'Customer delivery uploads only support the return leg.',
    ]);
}

$customerId = (string) ($_SESSION['customer_id'] ?? '');
$options = [];

if ($hasDeliveryReferencePayload) {
    $options['delivery_reference'] = $deliveryReference;
}

if ($hasDeliveryNotesPayload) {
    $options['delivery_notes'] = $deliveryNotes;
}

try {
    $updatedOrder = upload_customer_order_delivery_receipt_for_customer(
        $customerId,
        $orderId,
        $imageDataUrl,
        __DIR__,
        $options
    );
} catch (Throwable $error) {
    customer_order_upload_delivery_receipt_respond(500, [
        'ok' => false,
        'message' => 'Delivery receipt upload failed due to a technical issue. Please try again.',
    ]);
}

if ($updatedOrder === null) {
    customer_order_upload_delivery_receipt_respond(409, [
        'ok' => false,
        'message' => 'This booking is not eligible for return-delivery receipt upload.',
    ]);
}

customer_order_upload_delivery_receipt_respond(200, [
    'ok' => true,
    'message' => 'Delivery receipt uploaded successfully.',
    'order' => $updatedOrder,
]);
