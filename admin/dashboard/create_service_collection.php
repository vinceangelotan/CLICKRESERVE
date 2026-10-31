<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('html_errors', '0');

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

require dirname(__DIR__, 2) . '/config/services_packages_repository.php';

function normalize_service_collection_folder_name_for_create($value)
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

function normalize_service_collection_label_for_create($value, $fallback)
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

function sanitize_service_collection_segment_for_create($value, $forceLowercase)
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

function build_service_collection_folder_for_create($categoryLabel, $collectionLabel)
{
    $categorySegment = sanitize_service_collection_segment_for_create($categoryLabel, true);
    $nameSegment = sanitize_service_collection_segment_for_create($collectionLabel, false);

    return normalize_service_collection_folder_name_for_create($categorySegment . '_' . $nameSegment);
}

function build_service_package_folder_for_collections($packageKey)
{
    return normalize_service_package_key((string) $packageKey);
}

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$packageKey = normalize_service_package_key((string) ($payload['packageKey'] ?? ''));
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

$servicePackages = load_services_packages_repository();

if (!isset($servicePackages[$packageKey]) || !is_array($servicePackages[$packageKey])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Service package was not found']);
    exit;
}

if (!empty($servicePackages[$packageKey]['archived'])) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'Service package is archived']);
    exit;
}

$packageFolder = build_service_package_folder_for_collections($packageKey);

if ($packageFolder === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Service package folder is unavailable']);
    exit;
}

$categoryLabel = normalize_service_collection_label_for_create($categoryLabelInput, 'Untitled');
$collectionLabel = normalize_service_collection_label_for_create($collectionLabelInput, 'Untitled');
$collectionFolder = build_service_collection_folder_for_create($categoryLabel, $collectionLabel);

if ($collectionFolder === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Collection folder could not be generated from Main Tag and Collection Name.']);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$packageDirectory = $projectRoot
    . DIRECTORY_SEPARATOR . 'assets'
    . DIRECTORY_SEPARATOR . 'service_packages'
    . DIRECTORY_SEPARATOR . $packageFolder;

if (!is_dir($packageDirectory) && !@mkdir($packageDirectory, 0777, true) && !is_dir($packageDirectory)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to create service package folder']);
    exit;
}

$collectionDirectory = $packageDirectory . DIRECTORY_SEPARATOR . $collectionFolder;

if (is_dir($collectionDirectory)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'Another collection already uses this Main Tag and Collection Name.']);
    exit;
}

if (!@mkdir($collectionDirectory, 0777, true) && !is_dir($collectionDirectory)) {
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
