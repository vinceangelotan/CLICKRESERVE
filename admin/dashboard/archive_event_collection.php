<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || isset($_SESSION['customer_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

require dirname(__DIR__, 2) . '/config/event_packages_repository.php';
require dirname(__DIR__, 2) . '/config/event_collections_archive_repository.php';

function parse_event_collection_labels($folderName)
{
    $normalized = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', (string) $folderName)) ?? '');
    if ($normalized === '') {
        return [
            'category' => 'Untitled',
            'name' => 'Untitled',
        ];
    }

    $parts = explode('_', (string) $folderName, 2);
    $categoryRaw = $parts[0] ?? (string) $folderName;
    $nameRaw = $parts[1] ?? $categoryRaw;

    $toTitleCase = static function ($rawValue) {
        $tokens = preg_split('/\s+/', trim(str_replace(['_', '-'], ' ', (string) $rawValue))) ?: [];
        $lowerWords = ['and', 'or', 'the', 'for', 'to', 'of', 'in', 'on', 'at'];
        $normalizedWords = [];

        foreach ($tokens as $tokenIndex => $token) {
            if (preg_match('/^[A-Z0-9]{2,}$/', $token) === 1) {
                $normalizedWords[] = $token;
                continue;
            }

            $normalizedToken = ucfirst(strtolower((string) $token));
            if ($tokenIndex > 0 && in_array(strtolower($normalizedToken), $lowerWords, true)) {
                $normalizedToken = strtolower($normalizedToken);
            }

            $normalizedWords[] = $normalizedToken;
        }

        return $normalizedWords !== []
            ? implode(' ', $normalizedWords)
            : 'Untitled';
    };

    return [
        'category' => $toTitleCase($categoryRaw),
        'name' => $toTitleCase($nameRaw),
    ];
}

function build_event_collection_archive_key($packageKey, $collectionFolder)
{
    $seed = strtolower((string) $packageKey . '-' . str_replace('_', '-', (string) $collectionFolder));
    $seed = preg_replace('/[^a-z0-9-]+/', '-', $seed);
    $seed = trim((string) $seed, '-');

    if ($seed === '') {
        $seed = 'event-collection';
    }

    $suffix = '';

    try {
        $suffix = substr(bin2hex(random_bytes(2)), 0, 4);
    } catch (Throwable $exception) {
        $suffix = str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    return $seed . '-' . gmdate('YmdHis') . '-' . $suffix;
}

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$packageKey = normalize_event_package_key((string) ($payload['packageKey'] ?? ''));
$collectionFolder = normalize_event_collection_folder_name($payload['collectionFolder'] ?? '');
$categoryLabel = trim((string) ($payload['categoryLabel'] ?? ''));
$collectionLabel = trim((string) ($payload['collectionLabel'] ?? ''));

if ($packageKey === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Package key is required']);
    exit;
}

if ($collectionFolder === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Collection folder is required']);
    exit;
}

$eventPackages = load_event_packages_repository();

if (!isset($eventPackages[$packageKey]) || !is_array($eventPackages[$packageKey])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Event package was not found']);
    exit;
}

$packageRecord = $eventPackages[$packageKey];

if (!empty($packageRecord['archived'])) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'Event package is archived']);
    exit;
}

$packageFolder = trim((string) ($packageRecord['folder'] ?? ''));
if ($packageFolder === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Event package folder is unavailable']);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$packageDirectory = $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'event_packages' . DIRECTORY_SEPARATOR . $packageFolder;

if (!is_dir($packageDirectory)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Event package folder was not found']);
    exit;
}

$collectionDirectory = $packageDirectory . DIRECTORY_SEPARATOR . $collectionFolder;
if (!is_dir($collectionDirectory)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Collection folder was not found']);
    exit;
}

$archivedCollections = load_archived_event_collections_repository();

foreach ($archivedCollections as $archivedEntry) {
    if (!is_array($archivedEntry)) {
        continue;
    }

    $entryCollectionFolder = normalize_event_collection_folder_name($archivedEntry['collectionFolder'] ?? '');
    if ($entryCollectionFolder === '' || strcasecmp($entryCollectionFolder, $collectionFolder) !== 0) {
        continue;
    }

    $entryPackageFolder = trim((string) ($archivedEntry['packageFolder'] ?? ''));
    $entryPackageKey = normalize_event_package_key((string) ($archivedEntry['packageKey'] ?? ''));

    $isSamePackage = false;

    if ($entryPackageFolder !== '' && strcasecmp($entryPackageFolder, $packageFolder) === 0) {
        $isSamePackage = true;
    } elseif ($entryPackageKey !== '' && $entryPackageKey === $packageKey) {
        $isSamePackage = true;
    }

    if ($isSamePackage) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Collection is already archived']);
        exit;
    }
}

if ($categoryLabel === '' || $collectionLabel === '') {
    $labels = parse_event_collection_labels($collectionFolder);

    if ($categoryLabel === '') {
        $categoryLabel = (string) ($labels['category'] ?? 'Untitled');
    }

    if ($collectionLabel === '') {
        $collectionLabel = (string) ($labels['name'] ?? 'Untitled');
    }
}

$packageTitle = trim((string) ($packageRecord['title'] ?? strtoupper(str_replace('-', ' ', $packageKey))));
if ($packageTitle === '') {
    $packageTitle = strtoupper(str_replace('-', ' ', $packageKey));
}

$archiveEntry = [
    'archiveKey' => build_event_collection_archive_key($packageKey, $collectionFolder),
    'archivedAt' => gmdate('c'),
    'packageKey' => $packageKey,
    'packageTitle' => $packageTitle,
    'packageFolder' => $packageFolder,
    'collectionFolder' => $collectionFolder,
    'categoryLabel' => $categoryLabel,
    'collectionLabel' => $collectionLabel,
];

$archivedCollections[] = $archiveEntry;

if (!save_archived_event_collections_repository($archivedCollections)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to archive event collection']);
    exit;
}

echo json_encode([
    'ok' => true,
    'archivedEntry' => $archiveEntry,
]);
