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
require dirname(__DIR__, 2) . '/config/event_collection_images_archive_repository.php';

function parse_event_collection_labels_for_update($folderName)
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

function normalize_event_collection_label_value($value, $fallback)
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

function sanitize_event_collection_folder_segment($value, $forceLowercase)
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

function build_event_collection_folder_name($categoryLabel, $collectionLabel)
{
    $categorySegment = sanitize_event_collection_folder_segment($categoryLabel, true);
    $nameSegment = sanitize_event_collection_folder_segment($collectionLabel, false);

    return normalize_event_collection_folder_name($categorySegment . '_' . $nameSegment);
}

function remap_event_collection_image_path_for_folder($imagePath, $packageFolder, $sourceCollectionFolder, $targetCollectionFolder)
{
    $normalizedPath = normalize_event_collection_image_relative_path($imagePath);
    $normalizedPackageFolder = trim((string) $packageFolder);
    $normalizedSourceFolder = normalize_event_collection_folder_name($sourceCollectionFolder);
    $normalizedTargetFolder = normalize_event_collection_folder_name($targetCollectionFolder);

    if ($normalizedPath === '' || $normalizedPackageFolder === '' || $normalizedSourceFolder === '' || $normalizedTargetFolder === '') {
        return '';
    }

    if (strcasecmp($normalizedSourceFolder, $normalizedTargetFolder) === 0) {
        return $normalizedPath;
    }

    if (!is_event_collection_image_path_for_collection($normalizedPath, $normalizedPackageFolder, $normalizedSourceFolder)) {
        return '';
    }

    $sourcePrefix = 'assets/event_packages/' . $normalizedPackageFolder . '/' . $normalizedSourceFolder . '/';
    $suffix = substr($normalizedPath, strlen($sourcePrefix));

    if ($suffix === false || $suffix === '') {
        return '';
    }

    return 'assets/event_packages/' . $normalizedPackageFolder . '/' . $normalizedTargetFolder . '/' . $suffix;
}

function sanitize_event_collection_image_filename($value)
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

function build_event_collection_image_archive_key($packageKey, $collectionFolder, $imagePath)
{
    $seed = strtolower((string) $packageKey . '-' . (string) $collectionFolder . '-' . basename((string) $imagePath));
    $seed = preg_replace('/[^a-z0-9-]+/', '-', $seed);
    $seed = trim((string) $seed, '-');

    if ($seed === '') {
        $seed = 'event-image';
    }

    $suffix = '';

    try {
        $suffix = substr(bin2hex(random_bytes(2)), 0, 4);
    } catch (Throwable $exception) {
        $suffix = str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    return $seed . '-' . gmdate('YmdHis') . '-' . $suffix;
}

function save_event_collection_uploaded_image_from_data_url($dataUrl, $originalName, $collectionAbsoluteDirectory, $packageFolder, $collectionFolder)
{
    $value = trim((string) $dataUrl);

    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp|gif);base64,(.+)$/i', $value, $matches)) {
        throw new RuntimeException('Invalid image payload.');
    }

    $extension = strtolower((string) ($matches[1] ?? 'png'));
    if ($extension === 'jpg') {
        $extension = 'jpeg';
    }

    $binary = base64_decode((string) ($matches[2] ?? ''), true);
    if ($binary === false) {
        throw new RuntimeException('Invalid base64 image payload.');
    }

    $filenameBase = sanitize_event_collection_image_filename($originalName);
    $counter = 0;

    do {
        $suffix = $counter === 0 ? '' : '-' . (string) $counter;
        $filename = $filenameBase . $suffix . '.' . $extension;
        $absolutePath = $collectionAbsoluteDirectory . DIRECTORY_SEPARATOR . $filename;
        $counter++;
    } while (file_exists($absolutePath) && $counter < 2000);

    if (file_put_contents($absolutePath, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save collection image.');
    }

    return 'assets/event_packages/' . $packageFolder . '/' . $collectionFolder . '/' . $filename;
}

function is_collection_archived_for_package($packageKey, $packageFolder, $collectionFolder)
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

function is_same_event_package_reference($entryPackageKey, $entryPackageFolder, $packageKey, $packageFolder)
{
    if ($entryPackageFolder !== '' && strcasecmp($entryPackageFolder, $packageFolder) === 0) {
        return true;
    }

    if ($entryPackageKey !== '' && $entryPackageKey === $packageKey) {
        return true;
    }

    return false;
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

$excludedImagePathsInput = $payload['excludedImagePaths'] ?? [];
$restoreImagePathsInput = $payload['restoreImagePaths'] ?? [];
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

if (is_collection_archived_for_package($packageKey, $packageFolder, $collectionFolder)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'Collection is archived']);
    exit;
}

$projectRoot = dirname(__DIR__, 2);
$collectionDirectory = $projectRoot
    . DIRECTORY_SEPARATOR . 'assets'
    . DIRECTORY_SEPARATOR . 'event_packages'
    . DIRECTORY_SEPARATOR . $packageFolder
    . DIRECTORY_SEPARATOR . $collectionFolder;

if (!is_dir($collectionDirectory)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Collection folder was not found']);
    exit;
}

if ($categoryLabel === '' || $collectionLabel === '') {
    $labels = parse_event_collection_labels_for_update($collectionFolder);

    if ($categoryLabel === '') {
        $categoryLabel = (string) ($labels['category'] ?? 'Untitled');
    }

    if ($collectionLabel === '') {
        $collectionLabel = (string) ($labels['name'] ?? 'Untitled');
    }
}

$categoryLabel = normalize_event_collection_label_value($categoryLabel, 'Untitled');
$collectionLabel = normalize_event_collection_label_value($collectionLabel, 'Untitled');

$targetCollectionFolder = build_event_collection_folder_name($categoryLabel, $collectionLabel);
if ($targetCollectionFolder === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Collection folder could not be generated from Main Tag and Collection Name.']);
    exit;
}

$sourceCollectionFolder = $collectionFolder;
$collectionRenamed = false;

if (strcasecmp($targetCollectionFolder, $sourceCollectionFolder) !== 0) {
    if (is_collection_archived_for_package($packageKey, $packageFolder, $targetCollectionFolder)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'An archived collection already uses this Main Tag and Collection Name.']);
        exit;
    }

    $targetDirectory = $projectRoot
        . DIRECTORY_SEPARATOR . 'assets'
        . DIRECTORY_SEPARATOR . 'event_packages'
        . DIRECTORY_SEPARATOR . $packageFolder
        . DIRECTORY_SEPARATOR . $targetCollectionFolder;

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

$packageTitle = trim((string) ($packageRecord['title'] ?? strtoupper(str_replace('-', ' ', $packageKey))));
if ($packageTitle === '') {
    $packageTitle = strtoupper(str_replace('-', ' ', $packageKey));
}

$excludedImagePaths = [];

if (is_array($excludedImagePathsInput)) {
    foreach ($excludedImagePathsInput as $imagePath) {
        $normalizedPath = normalize_event_collection_image_relative_path($imagePath);

        if ($normalizedPath === '') {
            continue;
        }

        if ($collectionRenamed) {
            $normalizedPath = remap_event_collection_image_path_for_folder(
                $normalizedPath,
                $packageFolder,
                $sourceCollectionFolder,
                $collectionFolder
            );
        }

        if ($normalizedPath === '' || !is_event_collection_image_path_for_collection($normalizedPath, $packageFolder, $collectionFolder)) {
            continue;
        }

        $absolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);
        if (!is_file($absolutePath)) {
            continue;
        }

        $excludedImagePaths[$normalizedPath] = $normalizedPath;
    }
}

$restoreImagePaths = [];

if (is_array($restoreImagePathsInput)) {
    foreach ($restoreImagePathsInput as $imagePath) {
        $normalizedPath = normalize_event_collection_image_relative_path($imagePath);

        if ($normalizedPath === '') {
            continue;
        }

        if ($collectionRenamed) {
            $normalizedPath = remap_event_collection_image_path_for_folder(
                $normalizedPath,
                $packageFolder,
                $sourceCollectionFolder,
                $collectionFolder
            );
        }

        if ($normalizedPath === '' || !is_event_collection_image_path_for_collection($normalizedPath, $packageFolder, $collectionFolder)) {
            continue;
        }

        $restoreImagePaths[$normalizedPath] = $normalizedPath;
    }
}

$addedImages = [];

if (!is_array($addedImagesInput)) {
    $addedImagesInput = [];
}

if (count($addedImagesInput) > 30) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'You can add up to 30 images at a time.']);
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

$archivedImages = load_archived_event_collection_images_repository();
$archiveMetadataTouched = false;

foreach ($archivedImages as $entryIndex => $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $entryPackageFolder = trim((string) ($entry['packageFolder'] ?? ''));
    $entryPackageKey = normalize_event_package_key((string) ($entry['packageKey'] ?? ''));
    $entryCollectionFolder = normalize_event_collection_folder_name($entry['collectionFolder'] ?? '');

    if ($entryCollectionFolder === '' || strcasecmp($entryCollectionFolder, $sourceCollectionFolder) !== 0) {
        continue;
    }

    if (!is_same_event_package_reference($entryPackageKey, $entryPackageFolder, $packageKey, $packageFolder)) {
        continue;
    }

    $entryChanged = false;

    if ($collectionRenamed) {
        $entryImagePath = normalize_event_collection_image_relative_path($entry['imagePath'] ?? '');

        if ($entryImagePath !== '') {
            $remappedImagePath = remap_event_collection_image_path_for_folder(
                $entryImagePath,
                $packageFolder,
                $sourceCollectionFolder,
                $collectionFolder
            );

            if ($remappedImagePath !== '' && $remappedImagePath !== $entryImagePath) {
                $entry['imagePath'] = $remappedImagePath;
                $entry['imageName'] = basename($remappedImagePath);
                $entryChanged = true;
            }
        }

        if ((string) ($entry['collectionFolder'] ?? '') !== $collectionFolder) {
            $entry['collectionFolder'] = $collectionFolder;
            $entryChanged = true;
        }
    }

    if ((string) ($entry['categoryLabel'] ?? '') !== $categoryLabel) {
        $entry['categoryLabel'] = $categoryLabel;
        $entryChanged = true;
    }

    if ((string) ($entry['collectionLabel'] ?? '') !== $collectionLabel) {
        $entry['collectionLabel'] = $collectionLabel;
        $entryChanged = true;
    }

    if ($entryChanged) {
        $archivedImages[$entryIndex] = $entry;
        $archiveMetadataTouched = true;
    }
}

$archivedPathLookup = [];

foreach ($archivedImages as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $entryPackageFolder = trim((string) ($entry['packageFolder'] ?? ''));
    $entryPackageKey = normalize_event_package_key((string) ($entry['packageKey'] ?? ''));
    $entryCollectionFolder = normalize_event_collection_folder_name($entry['collectionFolder'] ?? '');

    if ($entryCollectionFolder === '' || strcasecmp($entryCollectionFolder, $collectionFolder) !== 0) {
        continue;
    }

    if (!is_same_event_package_reference($entryPackageKey, $entryPackageFolder, $packageKey, $packageFolder)) {
        continue;
    }

    $entryImagePath = normalize_event_collection_image_relative_path($entry['imagePath'] ?? '');
    if ($entryImagePath === '') {
        continue;
    }

    $archivedPathLookup[strtolower($entryImagePath)] = true;
}

$restoredCount = 0;

if ($restoreImagePaths !== []) {
    $remainingArchivedImages = [];

    foreach ($archivedImages as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entryPackageFolder = trim((string) ($entry['packageFolder'] ?? ''));
        $entryPackageKey = normalize_event_package_key((string) ($entry['packageKey'] ?? ''));
        $entryCollectionFolder = normalize_event_collection_folder_name($entry['collectionFolder'] ?? '');
        $entryImagePath = normalize_event_collection_image_relative_path($entry['imagePath'] ?? '');

        $isSameCollection = $entryCollectionFolder !== ''
            && strcasecmp($entryCollectionFolder, $collectionFolder) === 0;

        if (!$isSameCollection || $entryImagePath === '') {
            $remainingArchivedImages[] = $entry;
            continue;
        }

        $isSamePackage = is_same_event_package_reference($entryPackageKey, $entryPackageFolder, $packageKey, $packageFolder);

        if (!$isSamePackage) {
            $remainingArchivedImages[] = $entry;
            continue;
        }

        if (!isset($restoreImagePaths[$entryImagePath])) {
            $remainingArchivedImages[] = $entry;
            continue;
        }

        $restoredCount++;
    }

    if ($restoredCount > 0) {
        $archiveMetadataTouched = true;
    }

    $archivedImages = $remainingArchivedImages;

    foreach ($restoreImagePaths as $restorePath) {
        unset($archivedPathLookup[strtolower($restorePath)]);
    }
}

$newlyArchived = [];

foreach (array_values($excludedImagePaths) as $imagePath) {
    $pathLookupKey = strtolower($imagePath);

    if (isset($archivedPathLookup[$pathLookupKey])) {
        continue;
    }

    $archivedEntry = [
        'archiveKey' => build_event_collection_image_archive_key($packageKey, $collectionFolder, $imagePath),
        'archivedAt' => gmdate('c'),
        'packageKey' => $packageKey,
        'packageTitle' => $packageTitle,
        'packageFolder' => $packageFolder,
        'collectionFolder' => $collectionFolder,
        'categoryLabel' => $categoryLabel,
        'collectionLabel' => $collectionLabel,
        'imagePath' => $imagePath,
        'imageName' => basename($imagePath),
    ];

    $archivedImages[] = $archivedEntry;
    $newlyArchived[] = $archivedEntry;
    $archivedPathLookup[$pathLookupKey] = true;
    $archiveMetadataTouched = true;
}

foreach ($normalizedAddedImagesInput as $addedImageEntry) {
    try {
        $imagePath = save_event_collection_uploaded_image_from_data_url(
            (string) ($addedImageEntry['dataUrl'] ?? ''),
            (string) ($addedImageEntry['fileName'] ?? ''),
            $collectionDirectory,
            $packageFolder,
            $collectionFolder
        );
    } catch (Throwable $error) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
        exit;
    }

    $addedImages[] = [
        'tempId' => (string) ($addedImageEntry['tempId'] ?? ''),
        'imagePath' => $imagePath,
    ];
}

if ($archiveMetadataTouched && !save_archived_event_collection_images_repository($archivedImages)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to save collection image archive changes.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'renamed' => $collectionRenamed,
    'collectionFolder' => $collectionFolder,
    'categoryLabel' => $categoryLabel,
    'collectionLabel' => $collectionLabel,
    'restoredCount' => $restoredCount,
    'archivedCount' => count($newlyArchived),
    'addedCount' => count($addedImages),
    'addedImages' => $addedImages,
]);
