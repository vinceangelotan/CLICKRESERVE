<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function mark_read_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$isAdminSession = isset($_SESSION['user_id']) && !isset($_SESSION['customer_id']);
$isStaffSession = isset($_SESSION['staff_id']) && !isset($_SESSION['customer_id']);

if (!$isAdminSession && !$isStaffSession) {
    mark_read_respond(401, [
        'ok' => false,
        'message' => 'Unauthorized.',
    ]);
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mark_read_respond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
    ]);
}

require_once dirname(__DIR__, 2) . '/config/message_notifications_repository.php';

$rawBody = file_get_contents('php://input');
$decodedBody = json_decode((string) $rawBody, true);

$notificationId = trim((string) (
    (is_array($decodedBody) ? ($decodedBody['notificationId'] ?? '') : '')
    ?: ($_POST['notificationId'] ?? '')
));

if ($notificationId === '') {
    mark_read_respond(422, [
        'ok' => false,
        'message' => 'Notification id is required.',
    ]);
}

$markResult = mark_message_notification_as_read($notificationId);
$notifications = $markResult['notifications'];

if (!is_array($notifications)) {
    mark_read_respond(500, [
        'ok' => false,
        'message' => 'Unable to process notifications.',
    ]);
}

if ($markResult['changed']) {
    if (!save_message_notifications_repository($notifications)) {
        mark_read_respond(500, [
            'ok' => false,
            'message' => 'Unable to save read state.',
        ]);
    }
}

if ($markResult['updated'] === null) {
    mark_read_respond(404, [
        'ok' => false,
        'message' => 'Notification not found.',
        'unreadCount' => count_unread_message_notifications($notifications),
    ]);
}

mark_read_respond(200, [
    'ok' => true,
    'changed' => (bool) $markResult['changed'],
    'notificationId' => $notificationId,
    'unreadCount' => count_unread_message_notifications($notifications),
]);
