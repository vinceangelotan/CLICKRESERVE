<?php

require_once __DIR__ . '/event_collections_archive_repository.php';

function archived_event_collection_images_repository_path()
{
    return __DIR__ . '/archives/event_collection_images_archived.json';
}

function normalize_event_collection_image_relative_path($value)
{
    $normalized = trim(str_replace('\\', '/', rawurldecode((string) $value)));
    $normalized = ltrim($normalized, '/');
    $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return '';
    }

    return trim($normalized);
}

function is_event_collection_image_path_for_collection($imagePath, $packageFolder, $collectionFolder)
{
    $normalizedPath = normalize_event_collection_image_relative_path($imagePath);
    $normalizedPackageFolder = trim((string) $packageFolder);
    $normalizedCollectionFolder = normalize_event_collection_folder_name($collectionFolder);

    if ($normalizedPath === '' || $normalizedPackageFolder === '' || $normalizedCollectionFolder === '') {
        return false;
    }

    $expectedPrefix = 'assets/event_packages/' . $normalizedPackageFolder . '/' . $normalizedCollectionFolder . '/';

    return strpos(strtolower($normalizedPath), strtolower($expectedPrefix)) === 0;
}

function normalize_archived_event_collection_image_entry($entry)
{
    if (!is_array($entry)) {
        return null;
    }

    $archiveKey = trim((string) ($entry['archiveKey'] ?? ''));
    if ($archiveKey === '') {
        return null;
    }

    $packageKey = normalize_event_package_key((string) ($entry['packageKey'] ?? ''));
    $packageFolder = trim((string) ($entry['packageFolder'] ?? ''));
    $collectionFolder = normalize_event_collection_folder_name($entry['collectionFolder'] ?? '');
    $imagePath = normalize_event_collection_image_relative_path($entry['imagePath'] ?? '');

    if ($packageKey === '' || $packageFolder === '' || $collectionFolder === '' || $imagePath === '') {
        return null;
    }

    if (!is_event_collection_image_path_for_collection($imagePath, $packageFolder, $collectionFolder)) {
        return null;
    }

    $archivedAt = trim((string) ($entry['archivedAt'] ?? ''));
    if ($archivedAt === '') {
        $archivedAt = gmdate('c');
    }

    $packageTitle = trim((string) ($entry['packageTitle'] ?? strtoupper(str_replace('-', ' ', $packageKey))));
    if ($packageTitle === '') {
        $packageTitle = strtoupper(str_replace('-', ' ', $packageKey));
    }

    $categoryLabel = trim((string) ($entry['categoryLabel'] ?? ''));
    $collectionLabel = trim((string) ($entry['collectionLabel'] ?? ''));
    $imageName = trim((string) ($entry['imageName'] ?? basename($imagePath)));

    return [
        'archiveKey' => $archiveKey,
        'archivedAt' => $archivedAt,
        'packageKey' => $packageKey,
        'packageTitle' => $packageTitle,
        'packageFolder' => $packageFolder,
        'collectionFolder' => $collectionFolder,
        'categoryLabel' => $categoryLabel,
        'collectionLabel' => $collectionLabel,
        'imagePath' => $imagePath,
        'imageName' => $imageName,
    ];
}

function load_archived_event_collection_images_repository()
{
    $path = archived_event_collection_images_repository_path();

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

    $normalized = [];
    $seenArchiveKeys = [];

    foreach ($decoded as $entry) {
        $normalizedEntry = normalize_archived_event_collection_image_entry($entry);

        if (!is_array($normalizedEntry)) {
            continue;
        }

        $archiveKey = (string) $normalizedEntry['archiveKey'];
        if (isset($seenArchiveKeys[$archiveKey])) {
            continue;
        }

        $seenArchiveKeys[$archiveKey] = true;
        $normalized[] = $normalizedEntry;
    }

    return $normalized;
}

function save_archived_event_collection_images_repository($archivedImages)
{
    $path = archived_event_collection_images_repository_path();
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        return false;
    }

    $normalized = [];
    $seenArchiveKeys = [];

    foreach ((array) $archivedImages as $entry) {
        $normalizedEntry = normalize_archived_event_collection_image_entry($entry);

        if (!is_array($normalizedEntry)) {
            continue;
        }

        $archiveKey = (string) $normalizedEntry['archiveKey'];
        if (isset($seenArchiveKeys[$archiveKey])) {
            continue;
        }

        $seenArchiveKeys[$archiveKey] = true;
        $normalized[] = $normalizedEntry;
    }

    $encoded = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return false;
    }

    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}
