<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function customer_notifications_live_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['customer_id']) || isset($_SESSION['user_id'])) {
    customer_notifications_live_respond(401, [
        'ok' => false,
        'message' => 'Unauthorized.',
    ]);
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    customer_notifications_live_respond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
}

require_once __DIR__ . '/config/customer_orders_repository.php';
require_once __DIR__ . '/config/customer_notifications_repository.php';

$customerId = trim((string) ($_SESSION['customer_id'] ?? ''));

if ($customerId === '') {
    customer_notifications_live_respond(401, [
        'ok' => false,
        'message' => 'Unauthorized.',
    ]);
}

$limitRaw = (int) ($_GET['limit'] ?? 20);
$limit = max(1, min(40, $limitRaw));

$orders = load_customer_orders_for_customer($customerId);
$ordersSignature = '';

if (function_exists('customer_orders_live_state_signature_for_customer')) {
    $ordersSignature = (string) customer_orders_live_state_signature_for_customer($customerId);
} else {
    $encodedOrders = json_encode($orders, JSON_UNESCAPED_SLASHES);
    $ordersSignature = is_string($encodedOrders) ? sha1($encodedOrders) : '';
}

$allNotifications = load_customer_notifications_for_customer($customerId);
$notificationsForView = array_slice($allNotifications, 0, $limit);

$mappedNotifications = [];

foreach ($notificationsForView as $notification) {
    if (!is_array($notification)) {
        continue;
    }

    $mappedNotifications[] = map_customer_notification_for_frontend($notification);
}

customer_notifications_live_respond(200, [
    'ok' => true,
    'unreadCount' => count_unread_customer_notifications_for_customer($customerId, $allNotifications),
    'ordersSignature' => $ordersSignature,
    'orders' => $orders,
    'notifications' => $mappedNotifications,
]);
