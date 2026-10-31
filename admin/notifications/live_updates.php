<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function live_updates_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$isAdminSession = isset($_SESSION['user_id']) && !isset($_SESSION['customer_id']);
$isStaffSession = isset($_SESSION['staff_id']) && !isset($_SESSION['customer_id']);

if (!$isAdminSession && !$isStaffSession) {
    live_updates_respond(401, [
        'ok' => false,
        'message' => 'Unauthorized.',
    ]);
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    live_updates_respond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
}

require_once dirname(__DIR__, 2) . '/config/message_notifications_repository.php';
require_once dirname(__DIR__, 2) . '/config/customer_orders_repository.php';

$clearOrderRaw = trim((string) ($_GET['clear_order'] ?? '0'));
$shouldClearOrderNotifications = in_array(strtolower($clearOrderRaw), ['1', 'true', 'yes', 'on'], true);

$notifications = load_message_notifications_repository();

if ($shouldClearOrderNotifications) {
    $markResult = mark_all_message_notifications_as_read_by_type('order', $notifications);

    if (!is_array($markResult['notifications'] ?? null)) {
        live_updates_respond(500, [
            'ok' => false,
            'message' => 'Unable to process notifications.',
        ]);
    }

    $notifications = $markResult['notifications'];

    if (!empty($markResult['changed']) && !save_message_notifications_repository($notifications)) {
        live_updates_respond(500, [
            'ok' => false,
            'message' => 'Unable to save notification state.',
        ]);
    }
}

$latestOrderId = '';
$latestOrderCreatedAt = '';
$latestOrderTimestamp = null;
$orders = load_customer_orders_repository();

foreach ($orders as $orderRecord) {
    if (!is_array($orderRecord)) {
        continue;
    }

    $orderId = trim((string) ($orderRecord['id'] ?? ''));
    if ($orderId === '') {
        continue;
    }

    $createdAt = trim((string) ($orderRecord['created_at'] ?? ''));
    $createdTimestamp = strtotime($createdAt);

    if ($latestOrderId === '') {
        $latestOrderId = strtoupper($orderId);
        $latestOrderCreatedAt = $createdAt;
        $latestOrderTimestamp = $createdTimestamp !== false ? (int) $createdTimestamp : null;
        continue;
    }

    if ($createdTimestamp !== false && ($latestOrderTimestamp === null || (int) $createdTimestamp > $latestOrderTimestamp)) {
        $latestOrderId = strtoupper($orderId);
        $latestOrderCreatedAt = $createdAt;
        $latestOrderTimestamp = (int) $createdTimestamp;
    }
}

live_updates_respond(200, [
    'ok' => true,
    'unreadCount' => count_unread_message_notifications($notifications),
    'unreadOrderCount' => count_unread_message_notifications_by_type('order', $notifications),
    'latestOrderId' => $latestOrderId,
    'latestOrderCreatedAt' => $latestOrderCreatedAt,
    'ordersSignature' => customer_orders_live_state_signature($orders),
]);
