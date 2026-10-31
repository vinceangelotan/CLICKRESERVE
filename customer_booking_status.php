<?php
/**
 * Pre-check used by the cart page BEFORE the GCash popup opens.
 * Mirrors customer_order_check_customer_booking_limits() in config/customer_orders_repository.php
 * (the server still enforces the same rules when the order is submitted):
 *   Rule 5: no new order while an order is still ongoing / for return.
 *   Rule 4: a customer may hold at most 2 cameras across all active orders.
 *
 * GET ?requested=N   N = cameras currently in the customer's cart
 * Returns { ok, blocked, reason, message }
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['customer_id']) || isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'blocked' => false, 'message' => 'Please log in.']);
    exit;
}

require_once __DIR__ . '/config/customer_orders_repository.php';

$customerId = trim((string) $_SESSION['customer_id']);
$requestedCameras = max(0, (int) ($_GET['requested'] ?? 0));
$limit = customer_order_max_cameras_per_customer();
$orders = load_customer_orders_repository();

$blockingOrders = 0;
$blockingCameras = 0;
$activeCameras = 0;

foreach ((array) $orders as $record) {
    if (!is_array($record) || trim((string) ($record['customer_id'] ?? '')) !== $customerId) {
        continue;
    }

    if (!customer_order_record_occupies_inventory($record)) {
        continue;
    }

    $cameraCount = customer_order_count_cameras_in_items($record['items'] ?? []);
    $activeCameras += $cameraCount;

    if (in_array(normalize_customer_order_status_token($record['status'] ?? 'pending'), customer_order_blocking_return_status_tokens(), true)) {
        $blockingOrders++;
        $blockingCameras += $cameraCount;
    }
}

$plural = static function ($count) {
    return $count . ' camera' . ($count === 1 ? '' : 's');
};

$reason = '';
$message = '';

if ($blockingOrders > 0) {
    $reason = 'ongoing-booking';
    $message = 'Return ' . $plural(max(1, $blockingCameras)) . ' that you recently booked before placing a new order. '
        . 'You cannot book again while you still have an ongoing order.';
} elseif ($requestedCameras > $limit) {
    $reason = 'camera-limit';
    $message = 'Store policy allows only ' . $limit . ' cameras per customer. '
        . 'Please remove ' . $plural($requestedCameras - $limit) . ' from this order.';
} elseif ($activeCameras + $requestedCameras > $limit) {
    $reason = 'camera-limit';
    $message = 'Store policy allows only ' . $limit . ' cameras per customer. Return '
        . $plural(max(1, $activeCameras)) . ' that you recently booked before ordering another camera.';
}

echo json_encode([
    'ok' => true,
    'blocked' => $reason !== '',
    'reason' => $reason,
    'message' => $message,
], JSON_UNESCAPED_SLASHES);
