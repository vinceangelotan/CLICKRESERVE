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

function normalize_service_collection_folder_name_for_update($value)
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

function parse_service_collection_labels_for_update($folderName)
{
    $parts = explode('_', (string) $folderName, 2);
    $categoryRaw = $parts[0] ?? (string) $folderName;
    $nameRaw = $parts[1] ?? $categoryRaw;

    $toTitleCase = static function ($rawValue) {
        $normalized = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', (string) $rawValue)) ?? '');

        if ($normalized === '') {
            return 'Untitled';
        }

        $lowerWords = ['and', 'or', 'the', 'for', 'to', 'of', 'in', 'on', 'at'];
        $parts = preg_split('/\s+/', $normalized) ?: [];
        $formatted = [];

        foreach ($parts as $index => $part) {
            if (preg_match('/^[A-Z0-9]{2,}$/', $part) === 1) {
                $formatted[] = $part;
                continue;
            }

            $token = ucfirst(strtolower($part));
            if ($index > 0 && in_array(strtolower($token), $lowerWords, true)) {
                $token = strtolower($token);
            }

            $formatted[] = $token;
        }

        return $formatted !== [] ? implode(' ', $formatted) : 'Untitled';
    };

    return [
        'category' => $toTitleCase($categoryRaw),
        'name' => $toTitleCase($nameRaw),
    ];
}

function normalize_service_collection_label_for_update($value, $fallback)
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

function sanitize_service_collection_segment_for_update($value, $forceLowercase)
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

function build_service_collection_folder_name_for_update($categoryLabel, $collectionLabel)
{
    $categorySegment = sanitize_service_collection_segment_for_update($categoryLabel, true);
    $nameSegment = sanitize_service_collection_segment_for_update($collectionLabel, false);

    return normalize_service_collection_folder_name_for_update($categorySegment . '_' . $nameSegment);
}

function build_service_package_folder_for_update($packageKey)
{
    return normalize_service_package_key((string) $packageKey);
}

function normalize_service_collection_image_relative_path_for_update($value)
{
    $normalized = trim(str_replace('\\', '/', (string) $value));
    $normalized = ltrim($normalized, '/');

    if ($normalized === '') {
        return '';
    }

    if (strpos($normalized, "\0") !== false) {
        return '';
    }

    if (strpos($normalized, '../') !== false || strpos($normalized, '/..') !== false) {
        return '';
    }

    return $normalized;
}

function is_service_collection_image_path_for_collection($imagePath, $packageFolder, $collectionFolder)
{
    $normalizedPath = normalize_service_collection_image_relative_path_for_update($imagePath);
    $normalizedPackageFolder = trim((string) $packageFolder);
    $normalizedCollectionFolder = normalize_service_collection_folder_name_for_update($collectionFolder);

    if ($normalizedPath === '' || $normalizedPackageFolder === '' || $normalizedCollectionFolder === '') {
        return false;
    }

    $expectedPrefix = 'assets/service_packages/' . $normalizedPackageFolder . '/' . $normalizedCollectionFolder . '/';

    return strpos(strtolower($normalizedPath), strtolower($expectedPrefix)) === 0;
}

function remap_service_collection_image_path_for_folder($imagePath, $packageFolder, $sourceCollectionFolder, $targetCollectionFolder)
{
    $normalizedPath = normalize_service_collection_image_relative_path_for_update($imagePath);
    $normalizedPackageFolder = trim((string) $packageFolder);
    $normalizedSourceFolder = normalize_service_collection_folder_name_for_update($sourceCollectionFolder);
    $normalizedTargetFolder = normalize_service_collection_folder_name_for_update($targetCollectionFolder);

    if ($normalizedPath === '' || $normalizedPackageFolder === '' || $normalizedSourceFolder === '' || $normalizedTargetFolder === '') {
        return '';
    }

    if (strcasecmp($normalizedSourceFolder, $normalizedTargetFolder) === 0) {
        return $normalizedPath;
    }

    if (!is_service_collection_image_path_for_collection($normalizedPath, $normalizedPackageFolder, $normalizedSourceFolder)) {
        return '';
    }

    $sourcePrefix = 'assets/service_packages/' . $normalizedPackageFolder . '/' . $normalizedSourceFolder . '/';
    $suffix = substr($normalizedPath, strlen($sourcePrefix));

    if ($suffix === false || $suffix === '') {
        return '';
    }

    return 'assets/service_packages/' . $normalizedPackageFolder . '/' . $normalizedTargetFolder . '/' . $suffix;
}

function sanitize_service_collection_image_filename_for_update($value)
{
    $basename = trim((string) pathinfo((string) $value, PATHINFO_FILENAME));

    if ($basename === '') {
        $basename = 'collection-image';
    }

    $basename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $basename) ?? $basename;
    $basename = trim((string) $basename, '-_. ');

    if ($basename === '') {
        $basename = 'collection-image';
    }

    return substr($basename, 0, 64);
}

function save_service_collection_uploaded_image_from_data_url($dataUrl, $originalName, $collectionAbsoluteDirectory, $packageFolder, $collectionFolder, $allowVideoUploads)
{
    $value = trim((string) $dataUrl);

    if (!preg_match('/^data:(image|video)\/([a-z0-9.+-]+);base64,(.+)$/i', $value, $matches)) {
        throw new RuntimeException('Invalid media payload.');
    }

    $mediaKind = strtolower((string) ($matches[1] ?? 'image'));
    $extension = strtolower((string) ($matches[2] ?? 'png'));
    $binaryPayload = (string) ($matches[3] ?? '');

    if ($mediaKind === 'image' && !in_array($extension, ['png', 'jpeg', 'jpg', 'webp', 'gif'], true)) {
        throw new RuntimeException('Unsupported image format.');
    }

    if ($mediaKind === 'video') {
        if (!$allowVideoUploads) {
            throw new RuntimeException('Videos are only allowed for Photography + Videography packages.');
        }

        if (!in_array($extension, ['mp4', 'webm', 'ogg'], true)) {
            throw new RuntimeException('Unsupported video format. Use MP4, WEBM, or OGG.');
        }
    }

    if ($extension === 'jpg') {
        $extension = 'jpeg';
    }

    $binary = base64_decode($binaryPayload, true);
    if ($binary === false) {
        throw new RuntimeException('Invalid base64 media payload.');
    }

    $filenameBase = sanitize_service_collection_image_filename_for_update($originalName);
    $counter = 0;

    do {
        $suffix = $counter === 0 ? '' : '-' . (string) $counter;
        $filename = $filenameBase . $suffix . '.' . $extension;
        $absolutePath = $collectionAbsoluteDirectory . DIRECTORY_SEPARATOR . $filename;
        $counter++;
    } while (file_exists($absolutePath) && $counter < 2000);

    if (@file_put_contents($absolutePath, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save collection media.');
    }

    return [
        'path' => 'assets/service_packages/' . $packageFolder . '/' . $collectionFolder . '/' . $filename,
        'type' => $mediaKind,
    ];
}

$payloadRaw = file_get_contents('php://input');
$payload = json_decode((string) $payloadRaw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$packageKey = normalize_service_package_key((string) ($payload['packageKey'] ?? ''));
$collectionFolder = normalize_service_collection_folder_name_for_update($payload['collectionFolder'] ?? '');
$categoryLabel = trim((string) ($payload['categoryLabel'] ?? ''));
$collectionLabel = trim((string) ($payload['collectionLabel'] ?? ''));
$excludedImagePathsInput = $payload['excludedImagePaths'] ?? [];
$addedImagesInput = $payload['addedImages'] ?? [];

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

$servicePackageType = normalize_service_package_type((string) ($servicePackages[$packageKey]['serviceType'] ?? default_service_package_type()));
$allowVideoUploads = strpos($servicePackageType, 'videography') !== false;

$packageFolder = build_service_package_folder_for_update($packageKey);

if ($packageFolder === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Service package folder is unavailable']);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$packageDirectory = $projectRoot
    . DIRECTORY_SEPARATOR . 'assets'
    . DIRECTORY_SEPARATOR . 'service_packages'
    . DIRECTORY_SEPARATOR . $packageFolder;

if (!is_dir($packageDirectory)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Service package folder was not found']);
    exit;
}

$collectionDirectory = $packageDirectory . DIRECTORY_SEPARATOR . $collectionFolder;

if (!is_dir($collectionDirectory)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Collection folder was not found']);
    exit;
}

if ($categoryLabel === '' || $collectionLabel === '') {
    $labels = parse_service_collection_labels_for_update($collectionFolder);

    if ($categoryLabel === '') {
        $categoryLabel = (string) ($labels['category'] ?? 'Untitled');
    }

    if ($collectionLabel === '') {
        $collectionLabel = (string) ($labels['name'] ?? 'Untitled');
    }
}

$categoryLabel = normalize_service_collection_label_for_update($categoryLabel, 'Untitled');
$collectionLabel = normalize_service_collection_label_for_update($collectionLabel, 'Untitled');

$targetCollectionFolder = build_service_collection_folder_name_for_update($categoryLabel, $collectionLabel);

if ($targetCollectionFolder === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Collection folder could not be generated from Main Tag and Collection Name.']);
    exit;
}

$sourceCollectionFolder = $collectionFolder;
$collectionRenamed = false;

if (strcasecmp($targetCollectionFolder, $sourceCollectionFolder) !== 0) {
    $targetDirectory = $packageDirectory . DIRECTORY_SEPARATOR . $targetCollectionFolder;

    if (is_dir($targetDirectory)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Another collection already uses this Main Tag and Collection Name.']);
        exit;
    }

    if (!@rename($collectionDirectory, $targetDirectory)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Unable to rename collection folder.']);
        exit;
    }

    $collectionFolder = $targetCollectionFolder;
    $collectionDirectory = $targetDirectory;
    $collectionRenamed = true;
}

$excludedImagePaths = [];

if (is_array($excludedImagePathsInput)) {
    foreach ($excludedImagePathsInput as $imagePath) {
        $normalizedPath = normalize_service_collection_image_relative_path_for_update($imagePath);

        if ($normalizedPath === '') {
            continue;
        }

        if ($collectionRenamed) {
            $normalizedPath = remap_service_collection_image_path_for_folder(
                $normalizedPath,
                $packageFolder,
                $sourceCollectionFolder,
                $collectionFolder
            );
        }

        if ($normalizedPath === '' || !is_service_collection_image_path_for_collection($normalizedPath, $packageFolder, $collectionFolder)) {
            continue;
        }

        $absolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        if (!is_file($absolutePath)) {
            continue;
        }

        $excludedImagePaths[$normalizedPath] = $absolutePath;
    }
}

$removedCount = 0;

foreach ($excludedImagePaths as $normalizedPath => $absolutePath) {
    if (@unlink($absolutePath)) {
        $removedCount++;
        continue;
    }

    if (is_file($absolutePath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Unable to remove one of the selected media files.']);
        exit;
    }
}

if (!is_array($addedImagesInput)) {
    $addedImagesInput = [];
}

if (count($addedImagesInput) > 30) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'You can add up to 30 files at a time.']);
    exit;
}

$normalizedAddedImagesInput = [];

foreach ($addedImagesInput as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $tempId = trim((string) ($entry['tempId'] ?? ''));
    $dataUrl = trim((string) ($entry['dataUrl'] ?? ''));
    $fileName = trim((string) ($entry['fileName'] ?? ''));

    if ($dataUrl === '') {
        continue;
    }

    if ($tempId === '') {
        $tempId = 'temp-' . (string) (count($normalizedAddedImagesInput) + 1);
    }

    $normalizedAddedImagesInput[] = [
        'tempId' => $tempId,
        'dataUrl' => $dataUrl,
        'fileName' => $fileName,
    ];
}

$addedImages = [];

foreach ($normalizedAddedImagesInput as $addedImageEntry) {
    try {
        $savedMedia = save_service_collection_uploaded_image_from_data_url(
            (string) ($addedImageEntry['dataUrl'] ?? ''),
            (string) ($addedImageEntry['fileName'] ?? ''),
            $collectionDirectory,
            $packageFolder,
            $collectionFolder,
            $allowVideoUploads
        );
    } catch (Throwable $error) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
        exit;
    }

    $mediaPath = trim((string) ($savedMedia['path'] ?? ''));
    $mediaType = trim((string) ($savedMedia['type'] ?? 'image'));

    $addedImages[] = [
        'tempId' => (string) ($addedImageEntry['tempId'] ?? ''),
        'imagePath' => $mediaPath,
        'mediaPath' => $mediaPath,
        'mediaType' => $mediaType,
    ];
}

echo json_encode([
    'ok' => true,
    'renamed' => $collectionRenamed,
    'collectionFolder' => $collectionFolder,
    'categoryLabel' => $categoryLabel,
    'collectionLabel' => $collectionLabel,
    'removedCount' => $removedCount,
    'addedCount' => count($addedImages),
    'addedImages' => $addedImages,
]);
