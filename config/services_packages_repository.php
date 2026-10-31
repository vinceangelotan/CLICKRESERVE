<?php

function services_packages_repository_path()
{
    return __DIR__ . '/services_packages.json';
}

function service_package_type_labels()
{
    return [
        'photography' => 'Photography',
        'photography-videography' => 'Photography + Videography',
    ];
}

function default_service_package_type()
{
    $labels = service_package_type_labels();

    foreach ($labels as $typeKey => $typeLabel) {
        return (string) $typeKey;
    }

    return 'photography';
}

function normalize_service_package_key($value)
{
    $normalized = strtolower(trim((string) $value));
    $normalized = preg_replace('/[^a-z0-9-]+/', '-', $normalized);
    $normalized = trim((string) $normalized, '-');

    return $normalized;
}

function normalize_service_package_type($value)
{
    $labels = service_package_type_labels();
    $normalized = normalize_service_package_key((string) $value);

    if (isset($labels[$normalized])) {
        return $normalized;
    }

    $aliases = [
        'photo-video' => 'photography-videography',
        'photo-and-video' => 'photography-videography',
        'photography-and-videography' => 'photography-videography',
        'photography-plus-videography' => 'photography-videography',
        'photography-video' => 'photography-videography',
    ];

    if (isset($aliases[$normalized])) {
        return $aliases[$normalized];
    }

    if (strpos($normalized, 'videography') !== false && strpos($normalized, 'photography') !== false) {
        return 'photography-videography';
    }

    if (strpos($normalized, 'photography') !== false) {
        return 'photography';
    }

    return default_service_package_type();
}

function clamp_service_package_duration_hour($value)
{
    $parsed = (int) $value;

    return max(1, min(24, $parsed));
}

function normalize_service_package_duration_unit($value)
{
    $normalized = strtolower(trim((string) $value));

    if (in_array($normalized, ['day', 'days'], true)) {
        return 'days';
    }

    return 'hours';
}

function clamp_service_package_duration_day($value)
{
    $parsed = (int) $value;

    return max(1, min(14, $parsed));
}

function clamp_service_package_duration_value($unit, $value)
{
    $normalizedUnit = normalize_service_package_duration_unit($unit);

    if ($normalizedUnit === 'days') {
        return clamp_service_package_duration_day($value);
    }

    return clamp_service_package_duration_hour($value);
}

function parse_service_package_duration_range_from_description($value)
{
    $description = strtolower(trim((string) $value));

    if ($description === '') {
        return ['min' => 1, 'max' => 1];
    }

    if (preg_match('/(\d{1,2})\s*(?:-|to)\s*(\d{1,2})\s*hours?/i', $description, $matches)) {
        $minHours = clamp_service_package_duration_hour($matches[1] ?? 1);
        $maxHours = clamp_service_package_duration_hour($matches[2] ?? $minHours);

        if ($maxHours < $minHours) {
            $maxHours = $minHours;
        }

        return ['min' => $minHours, 'max' => $maxHours];
    }

    if (preg_match('/(\d{1,2})\s*hours?/i', $description, $matches)) {
        $hours = clamp_service_package_duration_hour($matches[1] ?? 1);

        return ['min' => $hours, 'max' => $hours];
    }

    if (strpos($description, 'full day') !== false || strpos($description, 'whole day') !== false) {
        return ['min' => 8, 'max' => 8];
    }

    return ['min' => 1, 'max' => 1];
}

function services_packages_repository_defaults()
{
    return [
        'photography-package-1' => [
            'serviceType' => 'photography',
            'sortOrder' => 1,
            'title' => 'PACKAGE 1',
            'description' => '3 hours with 1 photographer, 200+ edited photos, Online gallery and download, No transportation fee around Carmona.',
            'price' => '3299.00',
            'discountPercent' => 0,
            'durationUnit' => 'hours',
            'durationValue' => 3,
            'thumbnail_images' => [],
            'camera1' => '',
            'camera2' => '',
            'backupCamera1' => '',
            'backupCamera2' => '',
        ],
        'photography-package-2' => [
            'serviceType' => 'photography',
            'sortOrder' => 2,
            'title' => 'PACKAGE 2',
            'description' => '5 hours with 2 photographers, 400+ edited photos, Online gallery and download, No transportation fee around Carmona and Laguna.',
            'price' => '8499.00',
            'discountPercent' => 0,
            'durationUnit' => 'hours',
            'durationValue' => 5,
            'thumbnail_images' => [],
            'camera1' => '',
            'camera2' => '',
            'backupCamera1' => '',
            'backupCamera2' => '',
        ],
        'photography-package-3' => [
            'serviceType' => 'photography',
            'sortOrder' => 3,
            'title' => 'PACKAGE 3',
            'description' => 'Full day with 2 photographers, 600+ edited photos, Online gallery and download, No transportation fee around Carmona and Laguna.',
            'price' => '10499.00',
            'discountPercent' => 0,
            'durationUnit' => 'hours',
            'durationValue' => 8,
            'thumbnail_images' => [],
            'camera1' => '',
            'camera2' => '',
            'backupCamera1' => '',
            'backupCamera2' => '',
        ],
        'photography-videography-package-1' => [
            'serviceType' => 'photography-videography',
            'sortOrder' => 1,
            'title' => 'PACKAGE 1',
            'description' => '3-4 hours with 1 photographer and 1 videographer, 200+ edited photos and videos, Online gallery and download, No transportation fee around Carmona.',
            'price' => '7499.00',
            'discountPercent' => 0,
            'durationUnit' => 'hours',
            'durationValue' => 4,
            'thumbnail_images' => [],
            'camera1' => '',
            'camera2' => '',
            'backupCamera1' => '',
            'backupCamera2' => '',
        ],
        'photography-videography-package-2' => [
            'serviceType' => 'photography-videography',
            'sortOrder' => 2,
            'title' => 'PACKAGE 2',
            'description' => '5-6 hours with 2 photographers and 1 videographer, 400+ edited photos and videos, Online gallery and download, No transportation fee around Carmona and Laguna.',
            'price' => '10899.00',
            'discountPercent' => 0,
            'durationUnit' => 'hours',
            'durationValue' => 6,
            'thumbnail_images' => [],
            'camera1' => '',
            'camera2' => '',
            'backupCamera1' => '',
            'backupCamera2' => '',
        ],
        'photography-videography-package-3' => [
            'serviceType' => 'photography-videography',
            'sortOrder' => 3,
            'title' => 'PACKAGE 3',
            'description' => 'Full day with 2 photographers, 1 videographer and 1 assistant, 600+ edited photos and videos, 20 page Photo album, Online gallery and download, No transportation fee around Carmona and Laguna.',
            'price' => '13899.00',
            'discountPercent' => 0,
            'durationUnit' => 'hours',
            'durationValue' => 8,
            'thumbnail_images' => [],
            'camera1' => '',
            'camera2' => '',
            'backupCamera1' => '',
            'backupCamera2' => '',
        ],
    ];
}

function normalize_service_package_record($key, $record, $defaults)
{
    $fallback = isset($defaults[$key]) && is_array($defaults[$key])
        ? $defaults[$key]
        : [
            'serviceType' => default_service_package_type(),
            'sortOrder' => 1,
            'title' => strtoupper(str_replace('-', ' ', $key)),
            'description' => '',
            'price' => '0.00',
            'discountPercent' => 0,
            'durationUnit' => 'hours',
            'durationValue' => 1,
            'thumbnail_images' => [],
            'camera1' => '',
            'camera2' => '',
            'backupCamera1' => '',
            'backupCamera2' => '',
        ];

    if (!is_array($record)) {
        $record = [];
    }

    $serviceType = normalize_service_package_type((string) ($record['serviceType'] ?? $fallback['serviceType'] ?? default_service_package_type()));

    $sortOrder = (int) ($record['sortOrder'] ?? $fallback['sortOrder'] ?? 1);
    $sortOrder = max(1, $sortOrder);

    $title = trim((string) ($record['title'] ?? $fallback['title']));
    if ($title === '') {
        $title = (string) $fallback['title'];
    }

    $description = trim((string) ($record['description'] ?? $fallback['description'] ?? ''));

    if (function_exists('mb_substr')) {
        $description = trim((string) mb_substr($description, 0, 256, 'UTF-8'));
    } else {
        $description = trim((string) substr($description, 0, 256));
    }

    $descriptionDurationRange = parse_service_package_duration_range_from_description($description);
    $durationUnit = normalize_service_package_duration_unit(
        $record['durationUnit']
            ?? $record['duration_unit']
            ?? ($fallback['durationUnit'] ?? 'hours')
    );
    $durationValue = $record['durationValue'] ?? $record['duration_value'] ?? null;

    $hasLegacyDuration = isset($record['durationHoursMin'])
        || isset($record['durationHoursMax'])
        || isset($record['durationMinHours'])
        || isset($record['durationMaxHours']);

    if (($durationValue === null || $durationValue === '') && $hasLegacyDuration) {
        $durationUnit = 'hours';
        $durationValue = $record['durationHoursMax']
            ?? $record['durationMaxHours']
            ?? $record['durationHoursMin']
            ?? $record['durationMinHours']
            ?? 1;
    }

    if ($durationValue === null || $durationValue === '') {
        $durationUnit = normalize_service_package_duration_unit($fallback['durationUnit'] ?? 'hours');
        $durationValue = $fallback['durationValue']
            ?? ($fallback['durationHoursMax'] ?? null)
            ?? ($fallback['durationHoursMin'] ?? null)
            ?? ($descriptionDurationRange['max'] ?? 1);
    }

    $durationValue = clamp_service_package_duration_value($durationUnit, $durationValue);

    $priceValue = (float) ($record['price'] ?? $fallback['price']);
    $priceValue = max(0, $priceValue);

    $discountValue = (int) ($record['discountPercent'] ?? $fallback['discountPercent']);
    $discountValue = max(0, min(95, $discountValue));

    $thumbnailImagesInput = $record['thumbnail_images'] ?? $fallback['thumbnail_images'];
    $thumbnailImages = [];

    if (is_array($thumbnailImagesInput)) {
        foreach ($thumbnailImagesInput as $thumbnailImagePath) {
            $normalizedPath = trim(str_replace('\\', '/', (string) $thumbnailImagePath));
            $normalizedPath = ltrim($normalizedPath, '/');

            if ($normalizedPath === '' || isset($thumbnailImages[$normalizedPath])) {
                continue;
            }

            $thumbnailImages[$normalizedPath] = $normalizedPath;
        }
    }

    $camera1 = normalize_service_package_key((string) ($record['camera1'] ?? $fallback['camera1'] ?? ''));
    $camera2 = normalize_service_package_key((string) ($record['camera2'] ?? $fallback['camera2'] ?? ''));
    $backupCamera1 = normalize_service_package_key((string) ($record['backupCamera1'] ?? $fallback['backupCamera1'] ?? ''));
    $backupCamera2 = normalize_service_package_key((string) ($record['backupCamera2'] ?? $fallback['backupCamera2'] ?? ''));

    return [
        'serviceType' => $serviceType,
        'sortOrder' => $sortOrder,
        'title' => $title,
        'description' => $description,
        'price' => number_format($priceValue, 2, '.', ''),
        'discountPercent' => $discountValue,
        'durationUnit' => $durationUnit,
        'durationValue' => $durationValue,
        'thumbnail_images' => array_values($thumbnailImages),
        'camera1' => $camera1,
        'camera2' => $camera2,
        'backupCamera1' => $backupCamera1,
        'backupCamera2' => $backupCamera2,
    ];
}

function load_services_packages_repository()
{
    $defaults = services_packages_repository_defaults();
    $path = services_packages_repository_path();

    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return $defaults;
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        return $defaults;
    }

    $normalized = [];

    foreach ($decoded as $recordKey => $record) {
        $nextKey = is_string($recordKey) ? normalize_service_package_key($recordKey) : '';

        if ($nextKey === '' && is_array($record)) {
            $nextKey = normalize_service_package_key($record['key'] ?? '');
        }

        if ($nextKey === '' || isset($normalized[$nextKey])) {
            continue;
        }

        $normalized[$nextKey] = normalize_service_package_record($nextKey, $record, $defaults);
    }

    if ($normalized === []) {
        return $defaults;
    }

    foreach ($defaults as $defaultKey => $defaultRecord) {
        if (!isset($normalized[$defaultKey])) {
            $normalized[$defaultKey] = normalize_service_package_record($defaultKey, $defaultRecord, $defaults);
        }
    }

    return $normalized;
}

function save_services_packages_repository($servicePackages)
{
    if (!is_array($servicePackages) || $servicePackages === []) {
        return false;
    }

    $defaults = services_packages_repository_defaults();
    $normalized = [];

    foreach ($servicePackages as $key => $record) {
        $nextKey = normalize_service_package_key($key);

        if ($nextKey === '' || isset($normalized[$nextKey])) {
            continue;
        }

        $normalized[$nextKey] = normalize_service_package_record($nextKey, $record, $defaults);
    }

    if ($normalized === []) {
        return false;
    }

    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    return file_put_contents(services_packages_repository_path(), $encoded . PHP_EOL, LOCK_EX) !== false;
}
