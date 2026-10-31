<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function customer_order_submit_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    customer_order_submit_respond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
}

if (!isset($_SESSION['customer_id']) || isset($_SESSION['user_id'])) {
    customer_order_submit_respond(401, [
        'ok' => false,
        'message' => 'Please log in to place a booking.',
    ]);
}

function normalize_customer_booking_phone($value)
{
    $rawPhone = trim((string) $value);

    if ($rawPhone === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $rawPhone) ?? '';

    if (strlen($digits) === 12 && strpos($digits, '63') === 0) {
        $digits = '0' . substr($digits, 2);
    }

    if (strlen($digits) === 10 && strpos($digits, '9') === 0) {
        $digits = '0' . $digits;
    }

    if (strlen($digits) !== 11 || strpos($digits, '09') !== 0) {
        return '';
    }

    return $digits;
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/customer_orders_repository.php';
require_once __DIR__ . '/config/message_notifications_repository.php';

$rawBody = file_get_contents('php://input');
$decodedBody = json_decode((string) $rawBody, true);

$payload = [];

if (is_array($decodedBody)) {
    if (isset($decodedBody['order']) && is_array($decodedBody['order'])) {
        $payload = $decodedBody['order'];
    } else {
        $payload = $decodedBody;
    }
}

$items = $payload['items'] ?? [];
if (!is_array($items) || count($items) === 0) {
    customer_order_submit_respond(422, [
        'ok' => false,
        'message' => 'At least one item is required to create a booking.',
    ]);
}

$customerId = (string) ($_SESSION['customer_id'] ?? '');
$customerName = (string) ($_SESSION['customer_name'] ?? ('Customer #' . $customerId));
$customerEmail = (string) ($_SESSION['customer_email'] ?? '');
$customerAccountsTable = $customerAccountsTable ?? 'customer_accounts';

$customerPhone = '';
$profileStmt = $conn->prepare("SELECT customer_phone FROM {$customerAccountsTable} WHERE id = ? LIMIT 1");

if ($profileStmt instanceof mysqli_stmt) {
    $customerIdInt = (int) $customerId;
    $profileStmt->bind_param('i', $customerIdInt);
    $profileStmt->execute();
    $profileResult = $profileStmt->get_result();
    $profileRow = $profileResult ? $profileResult->fetch_assoc() : null;
    $profileStmt->close();

    if (is_array($profileRow)) {
        $customerPhone = normalize_customer_booking_phone($profileRow['customer_phone'] ?? '');
    }
}

if ($customerPhone === '') {
    customer_order_submit_respond(422, [
        'ok' => false,
        'message' => 'Please add your contact number in Account Settings before booking.',
    ]);
}

$_SESSION['customer_phone'] = $customerPhone;

$submitErrorMessage = '';

$createdOrder = append_customer_order_for_customer(
    $customerId,
    $customerName,
    $customerEmail,
    $payload,
    $submitErrorMessage
);

if ($createdOrder === null) {
    $normalizedError = trim((string) $submitErrorMessage);

    if ($normalizedError !== '') {
        customer_order_submit_respond(422, [
            'ok' => false,
            'message' => $normalizedError,
        ]);
    }

    customer_order_submit_respond(500, [
        'ok' => false,
        'message' => 'Unable to save your booking right now.',
    ]);
}

$createdOrderId = trim((string) ($createdOrder['id'] ?? ''));
if ($createdOrderId !== '') {
    append_order_placed_notification($createdOrderId);
}

customer_order_submit_respond(200, [
    'ok' => true,
    'message' => 'Booking saved as pending.',
    'order' => $createdOrder,
]);
