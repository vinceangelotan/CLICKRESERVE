<?php

function customer_gcash_profiles_repository_path()
{
    return __DIR__ . '/customer_gcash_profiles.json';
}

function normalize_customer_gcash_profile_customer_id($value)
{
    return trim((string) $value);
}

function normalize_customer_gcash_profile_name($value)
{
    $name = trim((string) $value);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;

    if ($name === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return trim((string) mb_substr($name, 0, 120));
    }

    return trim((string) substr($name, 0, 120));
}

function normalize_customer_gcash_profile_number($value)
{
    $number = trim((string) $value);
    $number = preg_replace('/[^0-9+()\-\s]/', '', $number) ?? $number;
    $number = preg_replace('/\s+/', ' ', $number) ?? $number;
    $number = trim((string) $number);

    if ($number === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return trim((string) mb_substr($number, 0, 40));
    }

    return trim((string) substr($number, 0, 40));
}

function normalize_customer_gcash_profile_record($record)
{
    if (!is_array($record)) {
        $record = [];
    }

    $customerId = normalize_customer_gcash_profile_customer_id($record['customer_id'] ?? $record['customerId'] ?? '');
    $gcashName = normalize_customer_gcash_profile_name($record['gcash_name'] ?? $record['gcashName'] ?? '');
    $gcashNumber = normalize_customer_gcash_profile_number($record['gcash_number'] ?? $record['gcashNumber'] ?? '');
    $updatedAt = trim((string) ($record['updated_at'] ?? $record['updatedAt'] ?? ''));

    if ($gcashName === '' && $gcashNumber === '') {
        $updatedAt = '';
    } elseif ($updatedAt === '') {
        $updatedAt = gmdate('c');
    }

    return [
        'customer_id' => $customerId,
        'gcash_name' => $gcashName,
        'gcash_number' => $gcashNumber,
        'updated_at' => $updatedAt,
    ];
}

function load_customer_gcash_profiles_repository()
{
    $path = customer_gcash_profiles_repository_path();

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

    $profiles = [];

    foreach ($decoded as $record) {
        $next = normalize_customer_gcash_profile_record($record);

        if ($next['customer_id'] === '') {
            continue;
        }

        $profiles[] = $next;
    }

    return $profiles;
}

function save_customer_gcash_profiles_repository($profiles)
{
    if (!is_array($profiles)) {
        return false;
    }

    $normalized = [];

    foreach ($profiles as $record) {
        $next = normalize_customer_gcash_profile_record($record);

        if ($next['customer_id'] === '') {
            continue;
        }

        $normalized[] = $next;
    }

    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return false;
    }

    return file_put_contents(customer_gcash_profiles_repository_path(), $encoded . PHP_EOL, LOCK_EX) !== false;
}

function find_customer_gcash_profile_for_customer($customerId, $profiles = null)
{
    $targetCustomerId = normalize_customer_gcash_profile_customer_id($customerId);

    if ($targetCustomerId === '') {
        return normalize_customer_gcash_profile_record([]);
    }

    $source = is_array($profiles) ? $profiles : load_customer_gcash_profiles_repository();

    foreach ($source as $record) {
        $next = normalize_customer_gcash_profile_record($record);

        if ($next['customer_id'] !== $targetCustomerId) {
            continue;
        }

        return $next;
    }

    return normalize_customer_gcash_profile_record([
        'customer_id' => $targetCustomerId,
    ]);
}

function upsert_customer_gcash_profile_for_customer($customerId, $gcashName, $gcashNumber)
{
    $targetCustomerId = normalize_customer_gcash_profile_customer_id($customerId);

    if ($targetCustomerId === '') {
        return null;
    }

    $profiles = load_customer_gcash_profiles_repository();
    $normalizedName = normalize_customer_gcash_profile_name($gcashName);
    $normalizedNumber = normalize_customer_gcash_profile_number($gcashNumber);
    $hasValues = $normalizedName !== '' || $normalizedNumber !== '';
    $didMatch = false;

    foreach ($profiles as $index => $record) {
        $next = normalize_customer_gcash_profile_record($record);

        if ($next['customer_id'] !== $targetCustomerId) {
            $profiles[$index] = $next;
            continue;
        }

        $didMatch = true;

        if (!$hasValues) {
            unset($profiles[$index]);
            continue;
        }

        $next['gcash_name'] = $normalizedName;
        $next['gcash_number'] = $normalizedNumber;
        $next['updated_at'] = gmdate('c');
        $profiles[$index] = normalize_customer_gcash_profile_record($next);
    }

    if (!$didMatch && $hasValues) {
        $profiles[] = normalize_customer_gcash_profile_record([
            'customer_id' => $targetCustomerId,
            'gcash_name' => $normalizedName,
            'gcash_number' => $normalizedNumber,
            'updated_at' => gmdate('c'),
        ]);
    }

    $profiles = array_values($profiles);

    if (!save_customer_gcash_profiles_repository($profiles)) {
        return null;
    }

    return find_customer_gcash_profile_for_customer($targetCustomerId, $profiles);
}

function map_customer_gcash_profile_for_frontend($record)
{
    $normalized = normalize_customer_gcash_profile_record($record);

    return [
        'customerId' => (string) ($normalized['customer_id'] ?? ''),
        'gcashName' => (string) ($normalized['gcash_name'] ?? ''),
        'gcashNumber' => (string) ($normalized['gcash_number'] ?? ''),
        'updatedAt' => (string) ($normalized['updated_at'] ?? ''),
    ];
}
