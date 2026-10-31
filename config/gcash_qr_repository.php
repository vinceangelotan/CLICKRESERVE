<?php

function gcash_qr_repository_path()
{
    return __DIR__ . '/gcash_qr.json';
}

function default_gcash_qr_repository_record()
{
    return [
        'qrImagePath' => '',
        'accountName' => '',
        'accountNumber' => '',
        'updatedAt' => ''
    ];
}

function normalize_gcash_qr_text_field($value, $maxLength = 120)
{
    $normalized = trim((string) $value);
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

    if ($normalized === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return trim((string) mb_substr($normalized, 0, max(1, (int) $maxLength)));
    }

    return trim((string) substr($normalized, 0, max(1, (int) $maxLength)));
}

function normalize_gcash_qr_number_field($value)
{
    $normalized = trim((string) $value);
    $normalized = preg_replace('/[^0-9+\-\s()]+/', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

    return normalize_gcash_qr_text_field($normalized, 40);
}

function normalize_gcash_qr_repository_record($record)
{
    if (!is_array($record)) {
        $record = [];
    }

    $default = default_gcash_qr_repository_record();

    $qrImagePath = trim((string) ($record['qrImagePath'] ?? $record['qr_image_path'] ?? $default['qrImagePath']));
    $accountName = normalize_gcash_qr_text_field($record['accountName'] ?? $record['account_name'] ?? $default['accountName'], 120);
    $accountNumber = normalize_gcash_qr_number_field($record['accountNumber'] ?? $record['account_number'] ?? $default['accountNumber']);
    $updatedAt = trim((string) ($record['updatedAt'] ?? $record['updated_at'] ?? $default['updatedAt']));

    if ($updatedAt === '') {
        $updatedAt = gmdate('c');
    }

    return [
        'qrImagePath' => $qrImagePath,
        'accountName' => $accountName,
        'accountNumber' => $accountNumber,
        'updatedAt' => $updatedAt
    ];
}

function load_gcash_qr_repository()
{
    $path = gcash_qr_repository_path();

    if (!is_file($path)) {
        return default_gcash_qr_repository_record();
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return default_gcash_qr_repository_record();
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return default_gcash_qr_repository_record();
    }

    return normalize_gcash_qr_repository_record($decoded);
}

function save_gcash_qr_repository($record)
{
    $normalized = normalize_gcash_qr_repository_record($record);
    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($encoded === false) {
        return false;
    }

    return file_put_contents(gcash_qr_repository_path(), $encoded . PHP_EOL, LOCK_EX) !== false;
}

function save_gcash_qr_image_from_data_url($imageDataUrl, $projectRoot)
{
    $dataUrl = trim((string) $imageDataUrl);

    if (!preg_match('/^data:image\/(png|jpe?g|webp);base64,(.+)$/i', $dataUrl, $matches)) {
        throw new RuntimeException('Invalid QR image payload. Please crop and save again.');
    }

    $binary = base64_decode((string) ($matches[2] ?? ''), true);
    if ($binary === false) {
        throw new RuntimeException('Invalid QR image data.');
    }

    $extensionRaw = strtolower((string) ($matches[1] ?? 'png'));
    $extension = $extensionRaw === 'jpeg' ? 'jpg' : $extensionRaw;

    $targetDirRelative = 'assets/gcash_qr';
    $targetDir = rtrim((string) $projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $targetDirRelative);

    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Unable to access GCash QR image directory.');
    }

    $filename = 'gcash-qr.' . $extension;
    $absolutePath = $targetDir . DIRECTORY_SEPARATOR . $filename;

    if (file_put_contents($absolutePath, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save GCash QR image.');
    }

    return $targetDirRelative . '/' . $filename;
}
