<?php

function message_notifications_repository_path()
{
    return __DIR__ . '/message_notifications.json';
}

function message_notification_generate_id()
{
    try {
        return 'notif-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    } catch (Throwable $error) {
        return 'notif-' . gmdate('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
    }
}

function normalize_message_notification_attachment_path($path)
{
    $normalized = trim(str_replace('\\', '/', rawurldecode((string) $path)));
    $normalized = ltrim($normalized, '/');
    $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return '';
    }

    return $normalized;
}

function normalize_message_notification_type($value)
{
    $type = strtolower(trim((string) $value));
    $type = preg_replace('/[^a-z0-9_-]+/', '', $type) ?? $type;

    if ($type === '') {
        return 'message';
    }

    return $type;
}

function normalize_message_notification_summary($value)
{
    $summary = trim((string) $value);
    $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;

    if ($summary === '') {
        return '';
    }

    $maxLength = 160;

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($summary) > $maxLength) {
            return rtrim((string) mb_substr($summary, 0, $maxLength - 1)) . '…';
        }

        return $summary;
    }

    if (strlen($summary) > $maxLength) {
        return rtrim(substr($summary, 0, $maxLength - 1)) . '...';
    }

    return $summary;
}

function normalize_message_notification_payload($type, $payload, $legacyRecord = null)
{
    $payloadInput = is_array($payload) ? $payload : [];
    $legacy = is_array($legacyRecord) ? $legacyRecord : [];

    if ($type !== 'message') {
        return $payloadInput;
    }

    $senderName = trim((string) ($payloadInput['sender_name'] ?? ($legacy['sender_name'] ?? 'Unknown customer')));
    if ($senderName === '') {
        $senderName = 'Unknown customer';
    }

    $senderEmail = trim((string) ($payloadInput['sender_email'] ?? ($legacy['sender_email'] ?? '')));
    $messageBody = trim((string) ($payloadInput['message'] ?? ($legacy['message'] ?? '')));

    $attachmentsInput = $payloadInput['attachments'] ?? ($legacy['attachments'] ?? []);
    $attachments = [];

    if (is_array($attachmentsInput)) {
        foreach ($attachmentsInput as $attachmentPath) {
            $normalizedPath = normalize_message_notification_attachment_path($attachmentPath);

            if ($normalizedPath === '' || isset($attachments[$normalizedPath])) {
                continue;
            }

            $attachments[$normalizedPath] = $normalizedPath;
        }
    }

    return [
        'sender_name' => $senderName,
        'sender_email' => $senderEmail,
        'message' => $messageBody,
        'attachments' => array_values($attachments),
    ];
}

function normalize_message_notification_record($record)
{
    if (!is_array($record)) {
        $record = [];
    }

    $id = trim((string) ($record['id'] ?? ''));
    if ($id === '') {
        $id = message_notification_generate_id();
    }

    $type = normalize_message_notification_type($record['type'] ?? 'message');

    $payload = normalize_message_notification_payload(
        $type,
        $record['payload'] ?? [],
        $record
    );

    $titleFallback = $type === 'message'
        ? trim((string) ($record['subject'] ?? 'Untitled message'))
        : 'Notification';

    $title = trim((string) ($record['title'] ?? $titleFallback));

    if ($title === '') {
        $title = $type === 'message' ? 'Untitled message' : 'Notification';
    }

    $summarySource = trim((string) ($record['summary'] ?? ''));

    if ($summarySource === '' && $type === 'message') {
        $summarySource = (string) ($payload['message'] ?? '');
    }

    if ($summarySource === '') {
        $summarySource = $title;
    }

    $summary = normalize_message_notification_summary($summarySource);

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
        'type' => $type,
        'title' => $title,
        'summary' => $summary,
        'payload' => $payload,
        'is_read' => $isRead,
        'created_at' => $createdAt,
        'read_at' => $readAt,
    ];
}

function load_message_notifications_repository()
{
    $path = message_notifications_repository_path();

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

        $notifications[] = normalize_message_notification_record($record);
    }

    return $notifications;
}

function save_message_notifications_repository($notifications)
{
    if (!is_array($notifications)) {
        return false;
    }

    $normalized = [];

    foreach ($notifications as $record) {
        if (!is_array($record)) {
            continue;
        }

        $normalized[] = normalize_message_notification_record($record);
    }

    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    return file_put_contents(message_notifications_repository_path(), $encoded . PHP_EOL, LOCK_EX) !== false;
}

function message_notification_build_order_id_lookup($orderIds)
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

function prune_message_order_notifications_by_order_ids($orderIds, $notifications = null)
{
    $source = is_array($notifications) ? $notifications : load_message_notifications_repository();
    $allowedOrderIds = message_notification_build_order_id_lookup($orderIds);
    $filtered = [];
    $changed = false;
    $removedCount = 0;

    foreach ($source as $record) {
        if (!is_array($record)) {
            $changed = true;
            $removedCount += 1;
            continue;
        }

        $normalized = normalize_message_notification_record($record);

        if ($normalized['type'] !== 'order') {
            $filtered[] = $normalized;
            continue;
        }

        $payload = is_array($normalized['payload'] ?? null) ? $normalized['payload'] : [];
        $orderId = strtoupper(trim((string) ($payload['order_id'] ?? '')));

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

function append_message_notification($senderName, $senderEmail, $subject, $message, $attachments)
{
    $notifications = load_message_notifications_repository();

    $normalizedSubject = trim((string) $subject);
    if ($normalizedSubject === '') {
        $normalizedSubject = 'Untitled message';
    }

    $normalizedMessage = trim((string) $message);

    $newRecord = normalize_message_notification_record([
        'id' => message_notification_generate_id(),
        'type' => 'message',
        'title' => $normalizedSubject,
        'summary' => normalize_message_notification_summary($normalizedMessage),
        'payload' => [
            'sender_name' => trim((string) $senderName),
            'sender_email' => trim((string) $senderEmail),
            'message' => $normalizedMessage,
            'attachments' => is_array($attachments) ? $attachments : [],
        ],
        'is_read' => false,
        'created_at' => gmdate('c'),
        'read_at' => '',
    ]);

    array_unshift($notifications, $newRecord);

    if (!save_message_notifications_repository($notifications)) {
        return null;
    }

    return $newRecord;
}

function append_order_placed_notification($orderId)
{
    $normalizedOrderId = strtoupper(trim((string) $orderId));

    if ($normalizedOrderId === '') {
        return null;
    }

    $notifications = load_message_notifications_repository();
    $title = 'A new order has been placed: ' . $normalizedOrderId;

    $newRecord = normalize_message_notification_record([
        'id' => message_notification_generate_id(),
        'type' => 'order',
        'title' => $title,
        'summary' => $title,
        'payload' => [
            'order_id' => $normalizedOrderId,
        ],
        'is_read' => false,
        'created_at' => gmdate('c'),
        'read_at' => '',
    ]);

    array_unshift($notifications, $newRecord);

    if (!save_message_notifications_repository($notifications)) {
        return null;
    }

    return $newRecord;
}

function append_order_delivery_notification($orderId, $title, $summary = '')
{
    $normalizedOrderId = strtoupper(trim((string) $orderId));
    $normalizedTitle = trim((string) $title);
    $normalizedSummary = normalize_message_notification_summary($summary);

    if ($normalizedOrderId === '') {
        return null;
    }

    if ($normalizedTitle === '') {
        $normalizedTitle = 'Delivery update for order ' . $normalizedOrderId;
    }

    if ($normalizedSummary === '') {
        $normalizedSummary = $normalizedTitle;
    }

    $notifications = load_message_notifications_repository();
    $nowTimestamp = time();

    foreach ($notifications as $record) {
        if (!is_array($record)) {
            continue;
        }

        $existing = normalize_message_notification_record($record);

        if ($existing['type'] !== 'order') {
            continue;
        }

        $payload = is_array($existing['payload'] ?? null) ? $existing['payload'] : [];
        if (strtoupper(trim((string) ($payload['order_id'] ?? ''))) !== $normalizedOrderId) {
            continue;
        }

        if ((string) ($existing['title'] ?? '') !== $normalizedTitle) {
            continue;
        }

        if ((string) ($existing['summary'] ?? '') !== $normalizedSummary) {
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

    $newRecord = normalize_message_notification_record([
        'id' => message_notification_generate_id(),
        'type' => 'order',
        'title' => $normalizedTitle,
        'summary' => $normalizedSummary,
        'payload' => [
            'order_id' => $normalizedOrderId,
            'event' => 'delivery',
        ],
        'is_read' => false,
        'created_at' => gmdate('c'),
        'read_at' => '',
    ]);

    array_unshift($notifications, $newRecord);

    if (!save_message_notifications_repository($notifications)) {
        return null;
    }

    return $newRecord;
}

function count_unread_message_notifications($notifications = null)
{
    $source = is_array($notifications) ? $notifications : load_message_notifications_repository();
    $count = 0;

    foreach ($source as $record) {
        if (!is_array($record)) {
            continue;
        }

        if (!($record['is_read'] ?? false)) {
            $count++;
        }
    }

    return $count;
}

function count_unread_message_notifications_by_type($type, $notifications = null)
{
    $targetType = normalize_message_notification_type($type);
    $source = is_array($notifications) ? $notifications : load_message_notifications_repository();
    $count = 0;

    foreach ($source as $record) {
        if (!is_array($record)) {
            continue;
        }

        $normalized = normalize_message_notification_record($record);

        if ($normalized['type'] !== $targetType) {
            continue;
        }

        if (!$normalized['is_read']) {
            $count++;
        }
    }

    return $count;
}

function mark_message_notification_as_read($notificationId, $notifications = null)
{
    $targetId = trim((string) $notificationId);

    if ($targetId === '') {
        return [
            'notifications' => is_array($notifications) ? $notifications : load_message_notifications_repository(),
            'changed' => false,
            'updated' => null,
        ];
    }

    $source = is_array($notifications) ? $notifications : load_message_notifications_repository();
    $changed = false;
    $updated = null;
    $readTimestamp = gmdate('c');

    foreach ($source as $index => $record) {
        if (!is_array($record)) {
            continue;
        }

        $normalized = normalize_message_notification_record($record);

        if ($normalized['id'] === $targetId && !$normalized['is_read']) {
            $normalized['is_read'] = true;
            $normalized['read_at'] = $readTimestamp;
            $changed = true;
            $updated = $normalized;
        } elseif ($normalized['id'] === $targetId) {
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

function mark_all_message_notifications_as_read($notifications = null)
{
    $source = is_array($notifications) ? $notifications : load_message_notifications_repository();
    $changed = false;
    $readTimestamp = gmdate('c');

    foreach ($source as $index => $record) {
        if (!is_array($record)) {
            continue;
        }

        $normalized = normalize_message_notification_record($record);

        if (!$normalized['is_read']) {
            $normalized['is_read'] = true;
            $normalized['read_at'] = $readTimestamp;
            $changed = true;
        }

        $source[$index] = $normalized;
    }

    return [
        'notifications' => array_values($source),
        'changed' => $changed,
    ];
}

function mark_all_message_notifications_as_read_by_type($type, $notifications = null)
{
    $targetType = normalize_message_notification_type($type);
    $source = is_array($notifications) ? $notifications : load_message_notifications_repository();
    $changed = false;
    $readTimestamp = gmdate('c');

    foreach ($source as $index => $record) {
        if (!is_array($record)) {
            continue;
        }

        $normalized = normalize_message_notification_record($record);

        if ($normalized['type'] === $targetType && !$normalized['is_read']) {
            $normalized['is_read'] = true;
            $normalized['read_at'] = $readTimestamp;
            $changed = true;
        }

        $source[$index] = $normalized;
    }

    return [
        'notifications' => array_values($source),
        'changed' => $changed,
    ];
}
