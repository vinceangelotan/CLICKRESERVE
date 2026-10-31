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

function normalize_event_collection_label_for_create($value, $fallback)
{
    $normalized = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    if ($normalized === '') {
        $normalized = trim((string) $fallback);
    }

    if ($normalized === '') {
        $normalized = 'Untitled';
    }

    return substr($normalized, 0, 120);
}

function sanitize_event_collection_segment_for_create($value, $forceLowercase)
{
    $normalized = trim((string) $value);
    $normalized = str_replace(["'", '"', '`'], '', $normalized);
    $normalized = preg_replace('/[^A-Za-z0-9]+/', '-', $normalized) ?? $normalized;
    $normalized = trim((string) $normalized, '-');

    if ($forceLowercase) {
        $normalized = strtolower($normalized);
    }

    if ($normalized === '') {
        $normalized = 'untitled';
    }

    return substr($normalized, 0, 96);
}

function build_event_collection_folder_for_create($categoryLabel, $collectionLabel)
{
    $categorySegment = sanitize_event_collection_segment_for_create($categoryLabel, true);
    $nameSegment = sanitize_event_collection_segment_for_create($collectionLabel, false);

    return normalize_event_collection_folder_name($categorySegment . '_' . $nameSegment);
}

function is_collection_archived_for_package_create($packageKey, $packageFolder, $collectionFolder)
{
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

        if ($entryPackageFolder !== '' && strcasecmp($entryPackageFolder, $packageFolder) === 0) {
            return true;
        }

        if ($entryPackageKey !== '' && $entryPackageKey === $packageKey) {
            return true;
        }
    }

    return false;
}

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$packageKey = normalize_event_package_key((string) ($payload['packageKey'] ?? ''));
$categoryLabelInput = trim((string) ($payload['categoryLabel'] ?? ''));
$collectionLabelInput = trim((string) ($payload['collectionLabel'] ?? ''));

if ($packageKey === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Package key is required']);
    exit;
}

if ($categoryLabelInput === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Main Tag is required']);
    exit;
}

if ($collectionLabelInput === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Collection Name is required']);
    exit;
}

$categoryLabel = normalize_event_collection_label_for_create($categoryLabelInput, 'Untitled');
$collectionLabel = normalize_event_collection_label_for_create($collectionLabelInput, 'Untitled');
$collectionFolder = build_event_collection_folder_for_create($categoryLabel, $collectionLabel);

if ($collectionFolder === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Collection folder could not be generated from Main Tag and Collection Name.']);
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

if (is_collection_archived_for_package_create($packageKey, $packageFolder, $collectionFolder)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'An archived collection already uses this Main Tag and Collection Name.']);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$packageDirectory = $projectRoot
    . DIRECTORY_SEPARATOR . 'assets'
    . DIRECTORY_SEPARATOR . 'event_packages'
    . DIRECTORY_SEPARATOR . $packageFolder;

if (!is_dir($packageDirectory)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Event package folder was not found']);
    exit;
}

$collectionDirectory = $packageDirectory . DIRECTORY_SEPARATOR . $collectionFolder;

if (is_dir($collectionDirectory)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'Another collection already uses this Main Tag and Collection Name.']);
    exit;
}

if (!mkdir($collectionDirectory, 0777, true) && !is_dir($collectionDirectory)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to create collection folder']);
    exit;
}

echo json_encode([
    'ok' => true,
    'newCollection' => [
        'packageKey' => $packageKey,
        'packageFolder' => $packageFolder,
        'collectionFolder' => $collectionFolder,
        'categoryLabel' => $categoryLabel,
        'collectionLabel' => $collectionLabel,
    ],
]);
