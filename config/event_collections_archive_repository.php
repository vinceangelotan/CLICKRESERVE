<?php

require_once __DIR__ . '/event_packages_repository.php';

function archived_event_collections_repository_path()
{
    return __DIR__ . '/archives/event_collections_archived.json';
}

function normalize_event_collection_folder_name($value)
{
    $normalized = trim(str_replace('\\', '/', (string) $value));

    if ($normalized === '' || $normalized === '.' || $normalized === '..') {
        return '';
    }

    if (strpos($normalized, '/') !== false) {
        return '';
    }

    $normalized = preg_replace('/\s+/', ' ', $normalized);

    return trim((string) $normalized);
}

function normalize_archived_event_collection_entry($entry)
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

    if ($packageKey === '' || $packageFolder === '' || $collectionFolder === '') {
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

    return [
        'archiveKey' => $archiveKey,
        'archivedAt' => $archivedAt,
        'packageKey' => $packageKey,
        'packageTitle' => $packageTitle,
        'packageFolder' => $packageFolder,
        'collectionFolder' => $collectionFolder,
        'categoryLabel' => $categoryLabel,
        'collectionLabel' => $collectionLabel,
    ];
}

function load_archived_event_collections_repository()
{
    $path = archived_event_collections_repository_path();

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
        $normalizedEntry = normalize_archived_event_collection_entry($entry);

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

function save_archived_event_collections_repository($archivedCollections)
{
    $path = archived_event_collections_repository_path();
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        return false;
    }

    $normalized = [];
    $seenArchiveKeys = [];

    foreach ((array) $archivedCollections as $entry) {
        $normalizedEntry = normalize_archived_event_collection_entry($entry);

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
