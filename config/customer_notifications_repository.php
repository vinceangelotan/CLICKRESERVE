<?php

function customer_notifications_repository_path()
{
    return __DIR__ . '/customer_notifications.json';
}

function customer_notification_generate_id()
{
    try {
        return 'cust-notif-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $error) {
        return 'cust-notif-' . gmdate('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
    }
}

function normalize_customer_notification_type($value)
{
    $type = strtolower(trim((string) $value));
    $type = preg_replace('/[^a-z0-9_-]+/', '-', $type) ?? $type;
    $type = trim($type, '-');

    if ($type === '') {
        return 'order-status';
    }

    return $type;
}

function normalize_customer_notification_status_token($value)
{
    $statusToken = strtolower(trim((string) $value));
    $statusToken = preg_replace('/[^a-z0-9-]+/', '-', $statusToken) ?? $statusToken;
    $statusToken = trim($statusToken, '-');

    return $statusToken;
}

function normalize_customer_notification_target_view($value)
{
    $view = strtolower(trim((string) $value));

    if ($view !== 'order-status') {
        $view = 'order-status';
    }

    return $view;
}

function normalize_customer_notification_summary($value)
{
    $summary = trim((string) $value);
    $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;

    if ($summary === '') {
        return '';
    }

    $maxLength = 220;

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($summary) > $maxLength) {
            return rtrim((string) mb_substr($summary, 0, $maxLength - 1)) . '...';
        }

        return $summary;
    }

    if (strlen($summary) > $maxLength) {
        return rtrim(substr($summary, 0, $maxLength - 1)) . '...';
    }

    return $summary;
}

function normalize_customer_notification_record($record)
{
    if (!is_array($record)) {
        $record = [];
    }

    $id = trim((string) ($record['id'] ?? ''));
    if ($id === '') {
        $id = customer_notification_generate_id();
    }

    $customerId = trim((string) ($record['customer_id'] ?? ''));
    $orderId = strtoupper(trim((string) ($record['order_id'] ?? '')));
    $type = normalize_customer_notification_type($record['type'] ?? 'order-status');
    $statusToken = normalize_customer_notification_status_token($record['status_token'] ?? '');

    $title = trim((string) ($record['title'] ?? 'Notification'));
    if ($title === '') {
        $title = 'Notification';
    }

    $summary = normalize_customer_notification_summary($record['summary'] ?? $title);
    if ($summary === '') {
        $summary = $title;
    }

    $targetView = normalize_customer_notification_target_view($record['target_view'] ?? 'order-status');
    $isRead = (bool) ($record['is_read'] ?? false);

    $createdAt = trim((string) ($record['created_at'] ?? ''));
    if ($createdAt === '') {
        $createdAt = gmdate('c');
    }

    $readAt = trim((string) ($record['read_at'] ?? ''));
    if (!$isRead) {
        $readAt = '';
    }

    return [
        'id' => $id,
        'customer_id' => $customerId,
        'type' => $type,
        'order_id' => $orderId,
        'status_token' => $statusToken,
        'title' => $title,
        'summary' => $summary,
        'target_view' => $targetView,
        'is_read' => $isRead,
        'created_at' => $createdAt,
        'read_at' => $readAt,
    ];
}

function load_customer_notifications_repository()
{
    $path = customer_notifications_repository_path();

    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return [];
    }

    $notifications = [];

    foreach ($decoded as $record) {
        if (!is_array($record)) {
            continue;
        }

        $normalized = normalize_customer_notification_record($record);

        if ($normalized['customer_id'] === '') {
            continue;
        }

        $notifications[] = $normalized;
    }

    return $notifications;
}

function save_customer_notifications_repository($notifications)
{
    if (!is_array($notifications)) {
        return false;
    }

    $normalized = [];

    foreach ($notifications as $record) {
        if (!is_array($record)) {
            continue;
        }

        $nextRecord = normalize_customer_notification_record($record);

        if ($nextRecord['customer_id'] === '') {
            continue;
        }

        $normalized[] = $nextRecord;
    }

    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return false;
    }

    return file_put_contents(customer_notifications_repository_path(), $encoded . PHP_EOL, LOCK_EX) !== false;
}

function customer_notification_build_order_id_lookup($orderIds)
{
    $lookup = [];

    if (!is_array($orderIds)) {
        return $lookup;
    }

    foreach ($orderIds as $key => $value) {
        $candidate = is_int($key) ? $value : $key;
        $normalizedOrderId = strtoupper(trim((string) $candidate));

        if ($normalizedOrderId === '') {
            $normalizedOrderId = strtoupper(trim((string) $value));
        }

        if ($normalizedOrderId === '') {
            continue;
        }

        $lookup[$normalizedOrderId] = true;
    }

    return $lookup;
}

function prune_customer_order_notifications_by_order_ids($orderIds, $notifications = null)
{
    $source = is_array($notifications) ? $notifications : load_customer_notifications_repository();
    $allowedOrderIds = customer_notification_build_order_id_lookup($orderIds);
    $filtered = [];
    $changed = false;
    $removedCount = 0;

    foreach ($source as $record) {
        if (!is_array($record)) {
            $changed = true;
            $removedCount += 1;
            continue;
        }

        $normalized = normalize_customer_notification_record($record);

        if ($normalized['customer_id'] === '') {
            $changed = true;
            $removedCount += 1;
            continue;
        }

        if ($normalized['type'] !== 'order-status') {
            $filtered[] = $normalized;
            continue;
        }

        $orderId = strtoupper(trim((string) ($normalized['order_id'] ?? '')));

        if ($orderId !== '' && isset($allowedOrderIds[$orderId])) {
            $filtered[] = $normalized;
            continue;
        }

        $changed = true;
        $removedCount += 1;
    }

    return [
        'notifications' => array_values($filtered),
        'changed' => $changed,
        'removedCount' => $removedCount,
    ];
}

function sort_customer_notifications_for_view($notifications)
{
    if (!is_array($notifications)) {
        return [];
    }

    $source = array_values($notifications);

    usort($source, function ($left, $right) {
        $leftRead = (bool) ($left['is_read'] ?? false);
        $rightRead = (bool) ($right['is_read'] ?? false);

        if ($leftRead !== $rightRead) {
            return $leftRead <=> $rightRead;
        }

        $leftTimestamp = strtotime((string) ($left['created_at'] ?? ''));
        $rightTimestamp = strtotime((string) ($right['created_at'] ?? ''));

        $leftValue = $leftTimestamp !== false ? (int) $leftTimestamp : 0;
        $rightValue = $rightTimestamp !== false ? (int) $rightTimestamp : 0;

        return $rightValue <=> $leftValue;
    });

    return $source;
}

function load_customer_notifications_for_customer($customerId, $notifications = null, $limit = 0)
{
    $targetCustomerId = trim((string) $customerId);

    if ($targetCustomerId === '') {
        return [];
    }

    $source = is_array($notifications) ? $notifications : load_customer_notifications_repository();
    $filtered = [];

    foreach ($source as $record) {
        if (!is_array($record)) {
            continue;
        }

        $normalized = normalize_customer_notification_record($record);

        if ($normalized['customer_id'] !== $targetCustomerId) {
            continue;
        }

        $filtered[] = $normalized;
    }

    $sorted = sort_customer_notifications_for_view($filtered);
    $resolvedLimit = (int) $limit;

    if ($resolvedLimit > 0) {
        $sorted = array_slice($sorted, 0, $resolvedLimit);
    }

    return array_values($sorted);
}

function count_unread_customer_notifications_for_customer($customerId, $notifications = null)
{
    $targetCustomerId = trim((string) $customerId);

    if ($targetCustomerId === '') {
        return 0;
    }

    $source = is_array($notifications) ? $notifications : load_customer_notifications_repository();
    $count = 0;

    foreach ($source as $record) {
        if (!is_array($record)) {
            continue;
        }

        $normalized = normalize_customer_notification_record($record);

        if ($normalized['customer_id'] !== $targetCustomerId) {
            continue;
        }

        if (!$normalized['is_read']) {
            $count += 1;
        }
    }

    return $count;
}

function mark_customer_notifications_as_read_by_type_for_customer($customerId, $notificationType, $notifications = null)
{
    $targetCustomerId = trim((string) $customerId);
    $targetType = normalize_customer_notification_type($notificationType);
    $source = is_array($notifications) ? $notifications : load_customer_notifications_repository();

    if ($targetCustomerId === '') {
        return [
            'notifications' => $source,
            'changed' => false,
            'updatedCount' => 0,
            'updatedNotificationIds' => [],
        ];
    }

    $changed = false;
    $updatedCount = 0;
    $updatedNotificationIds = [];
    $readAt = gmdate('c');

    foreach ($source as $index => $record) {
        if (!is_array($record)) {
            continue;
        }

        $normalized = normalize_customer_notification_record($record);

        if ($normalized['customer_id'] === $targetCustomerId && $normalized['type'] === $targetType) {
            if (!$normalized['is_read']) {
                $normalized['is_read'] = true;
                $normalized['read_at'] = $readAt;
                $changed = true;
                $updatedCount += 1;
                $updatedNotificationIds[] = (string) ($normalized['id'] ?? '');
            }
        }

        $source[$index] = $normalized;
    }

    return [
        'notifications' => array_values($source),
        'changed' => $changed,
        'updatedCount' => $updatedCount,
        'updatedNotificationIds' => $updatedNotificationIds,
    ];
}

function mark_customer_notification_as_read_for_customer($customerId, $notificationId, $notifications = null)
{
    $targetCustomerId = trim((string) $customerId);
    $targetNotificationId = trim((string) $notificationId);
    $source = is_array($notifications) ? $notifications : load_customer_notifications_repository();

    if ($targetCustomerId === '' || $targetNotificationId === '') {
        return [
            'notifications' => $source,
            'changed' => false,
            'updated' => null,
        ];
    }

    $changed = false;
    $updated = null;
    $readAt = gmdate('c');

    foreach ($source as $index => $record) {
        if (!is_array($record)) {
            continue;
        }

        $normalized = normalize_customer_notification_record($record);

        if ($normalized['customer_id'] === $targetCustomerId && $normalized['id'] === $targetNotificationId) {
            if (!$normalized['is_read']) {
                $normalized['is_read'] = true;
                $normalized['read_at'] = $readAt;
                $changed = true;
            }

            $updated = $normalized;
        }

        $source[$index] = $normalized;
    }

    return [
        'notifications' => array_values($source),
        'changed' => $changed,
        'updated' => $updated,
    ];
}

function build_customer_order_status_notification_content($orderId, $statusToken, $statusLabel, $cancelReason = '')
{
    $orderLabel = strtoupper(trim((string) $orderId));
    if ($orderLabel === '') {
        $orderLabel = 'your booking';
    }

    $normalizedStatusToken = normalize_customer_notification_status_token($statusToken);
    $statusText = trim((string) $statusLabel);

    if ($statusText === '') {
        $statusText = $normalizedStatusToken !== ''
            ? ucwords(str_replace('-', ' ', $normalizedStatusToken))
            : 'Updated';
    }

    $reason = trim((string) $cancelReason);

    if ($normalizedStatusToken === 'approved') {
        return [
            'title' => 'Payment approved for ' . $orderLabel,
            'summary' => 'Your payment was approved. Order status is now ' . $statusText . '.',
        ];
    }

    if ($normalizedStatusToken === 'rejected') {
        return [
            'title' => 'Payment rejected for ' . $orderLabel,
            'summary' => $reason !== ''
                ? 'Reason: ' . $reason
                : 'Your payment was rejected. Please review your order details.',
        ];
    }

    if ($normalizedStatusToken === 'refunded') {
        return [
            'title' => 'Payment refunded for ' . $orderLabel,
            'summary' => $reason !== ''
                ? 'Reason: ' . $reason
                : 'Your order payment has been refunded.',
        ];
    }

    if ($normalizedStatusToken === 'awaiting-refund') {
        return [
            'title' => 'Refund pending for ' . $orderLabel,
            'summary' => $reason !== ''
                ? 'Reason: ' . $reason . ' Refund is now being processed.'
                : 'Your approved order was canceled and is now awaiting refund processing.',
        ];
    }

    if ($normalizedStatusToken === 'canceled') {
        return [
            'title' => 'Order canceled: ' . $orderLabel,
            'summary' => $reason !== ''
                ? 'Reason: ' . $reason
                : 'Your order was canceled.',
        ];
    }

    return [
        'title' => 'Order status updated: ' . $orderLabel,
        'summary' => 'Your order status is now ' . $statusText . '.',
    ];
}

function append_customer_order_status_notification($customerId, $orderId, $statusToken, $statusLabel = '', $cancelReason = '')
{
    $targetCustomerId = trim((string) $customerId);
    $normalizedOrderId = strtoupper(trim((string) $orderId));
    $normalizedStatusToken = normalize_customer_notification_status_token($statusToken);

    if ($targetCustomerId === '' || $normalizedOrderId === '' || $normalizedStatusToken === '') {
        return null;
    }

    $content = build_customer_order_status_notification_content(
        $normalizedOrderId,
        $normalizedStatusToken,
        $statusLabel,
        $cancelReason
    );

    $notifications = load_customer_notifications_repository();
    $nowTimestamp = time();

    foreach ($notifications as $record) {
        if (!is_array($record)) {
            continue;
        }

        $existing = normalize_customer_notification_record($record);

        if ($existing['customer_id'] !== $targetCustomerId) {
            continue;
        }

        if ($existing['type'] !== 'order-status') {
            continue;
        }

        if ($existing['order_id'] !== $normalizedOrderId) {
            continue;
        }

        if ($existing['status_token'] !== $normalizedStatusToken) {
            continue;
        }

        if ($existing['title'] !== $content['title'] || $existing['summary'] !== $content['summary']) {
            continue;
        }

        $existingTimestamp = strtotime((string) ($existing['created_at'] ?? ''));
        if ($existingTimestamp === false) {
            continue;
        }

        if (($nowTimestamp - (int) $existingTimestamp) <= 90) {
            return $existing;
        }
    }

    $newRecord = normalize_customer_notification_record([
        'id' => customer_notification_generate_id(),
        'customer_id' => $targetCustomerId,
        'type' => 'order-status',
        'order_id' => $normalizedOrderId,
        'status_token' => $normalizedStatusToken,
        'title' => $content['title'],
        'summary' => $content['summary'],
        'target_view' => 'order-status',
        'is_read' => false,
        'created_at' => gmdate('c'),
        'read_at' => '',
    ]);

    array_unshift($notifications, $newRecord);

    if (!save_customer_notifications_repository($notifications)) {
        return null;
    }

    return $newRecord;
}

function append_customer_order_delivery_notification($customerId, $orderId, $title, $summary = '', $eventToken = 'delivery-update')
{
    $targetCustomerId = trim((string) $customerId);
    $normalizedOrderId = strtoupper(trim((string) $orderId));
    $normalizedTitle = trim((string) $title);
    $normalizedSummary = normalize_customer_notification_summary($summary);
    $normalizedEventToken = normalize_customer_notification_status_token($eventToken);

    if ($targetCustomerId === '' || $normalizedOrderId === '') {
        return null;
    }

    if ($normalizedTitle === '') {
        $normalizedTitle = 'Delivery update for ' . $normalizedOrderId;
    }

    if ($normalizedSummary === '') {
        $normalizedSummary = $normalizedTitle;
    }

    if ($normalizedEventToken === '') {
        $normalizedEventToken = 'delivery-update';
    }

    $notifications = load_customer_notifications_repository();
    $nowTimestamp = time();

    foreach ($notifications as $record) {
        if (!is_array($record)) {
            continue;
        }

        $existing = normalize_customer_notification_record($record);

        if ($existing['customer_id'] !== $targetCustomerId) {
            continue;
        }

        if ($existing['type'] !== 'order-status') {
            continue;
        }

        if ($existing['order_id'] !== $normalizedOrderId) {
            continue;
        }

        if ($existing['status_token'] !== $normalizedEventToken) {
            continue;
        }

        if ($existing['title'] !== $normalizedTitle || $existing['summary'] !== $normalizedSummary) {
            continue;
        }

        $existingTimestamp = strtotime((string) ($existing['created_at'] ?? ''));
        if ($existingTimestamp === false) {
            continue;
        }

        if (($nowTimestamp - (int) $existingTimestamp) <= 90) {
            return $existing;
        }
    }

    $newRecord = normalize_customer_notification_record([
        'id' => customer_notification_generate_id(),
        'customer_id' => $targetCustomerId,
        'type' => 'order-status',
        'order_id' => $normalizedOrderId,
        'status_token' => $normalizedEventToken,
        'title' => $normalizedTitle,
        'summary' => $normalizedSummary,
        'target_view' => 'order-status',
        'is_read' => false,
        'created_at' => gmdate('c'),
        'read_at' => '',
    ]);

    array_unshift($notifications, $newRecord);

    if (!save_customer_notifications_repository($notifications)) {
        return null;
    }

    return $newRecord;
}

function format_customer_notification_datetime($value)
{
    $timestamp = strtotime((string) $value);

    if ($timestamp === false) {
        return 'Unknown time';
    }

    return date('M d, Y g:i A', $timestamp);
}

function map_customer_notification_for_frontend($notification)
{
    $normalized = normalize_customer_notification_record($notification);

    return [
        'id' => (string) ($normalized['id'] ?? ''),
        'type' => (string) ($normalized['type'] ?? 'order-status'),
        'orderId' => (string) ($normalized['order_id'] ?? ''),
        'statusToken' => (string) ($normalized['status_token'] ?? ''),
        'title' => (string) ($normalized['title'] ?? 'Notification'),
        'summary' => (string) ($normalized['summary'] ?? ''),
        'targetView' => (string) ($normalized['target_view'] ?? 'order-status'),
        'isRead' => (bool) ($normalized['is_read'] ?? false),
        'createdAt' => (string) ($normalized['created_at'] ?? ''),
        'createdAtLabel' => format_customer_notification_datetime((string) ($normalized['created_at'] ?? '')),
    ];
}
