<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=20');

function search_suggestions_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function search_suggestions_normalize_text($value): string
{
    $normalized = strtolower(trim((string) $value));

    if ($normalized === '') {
        return '';
    }

    return trim((string) (preg_replace('/\s+/', ' ', $normalized) ?? ''));
}

function search_suggestions_normalize_digits($value): string
{
    return (string) (preg_replace('/[^0-9]/', '', (string) $value) ?? '');
}

function search_suggestions_format_price(float $value): string
{
    return 'P ' . number_format(max(0, $value), 2);
}

function search_suggestions_trim_description($value, int $maxChars = 120): string
{
    $description = trim((string) $value);

    if ($description === '') {
        return '';
    }

    if ($maxChars < 24) {
        $maxChars = 24;
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($description, 'UTF-8') <= $maxChars) {
            return $description;
        }

        return trim((string) mb_substr($description, 0, max(1, $maxChars - 1), 'UTF-8')) . '...';
    }

    if (strlen($description) <= $maxChars) {
        return $description;
    }

    return trim((string) substr($description, 0, max(1, $maxChars - 1))) . '...';
}

function search_suggestions_score_text(string $field, string $query, int $exactScore, int $prefixScore, int $containsScore): int
{
    if ($query === '' || $field === '') {
        return 0;
    }

    if ($field === $query) {
        return $exactScore;
    }

    if (strpos($field, $query) === 0) {
        return $prefixScore;
    }

    if (strpos($field, $query) !== false) {
        return $containsScore;
    }

    return 0;
}

function search_suggestions_product_score(array $product, string $queryText, string $queryDigits): int
{
    $score = 0;

    $brand = search_suggestions_normalize_text($product['brand'] ?? '');
    $name = search_suggestions_normalize_text($product['name'] ?? '');
    $tagline = search_suggestions_normalize_text($product['tagline'] ?? '');
    $specOne = search_suggestions_normalize_text($product['spec1'] ?? '');
    $specTwo = search_suggestions_normalize_text($product['spec2'] ?? '');

    if ($queryText !== '') {
        $score += search_suggestions_score_text($name, $queryText, 1300, 950, 740);
        $score += search_suggestions_score_text($brand, $queryText, 1100, 780, 620);
        $score += search_suggestions_score_text($tagline, $queryText, 0, 0, 520);
        $score += search_suggestions_score_text($specOne, $queryText, 0, 0, 360);
        $score += search_suggestions_score_text($specTwo, $queryText, 0, 0, 360);
    }

    if ($queryDigits !== '') {
        $basePrice = max(0, (float) ($product['price'] ?? 0));
        $discountPercent = max(0, min(95, (int) ($product['discountPercent'] ?? 0)));
        $discountedPrice = $basePrice * (1 - ($discountPercent / 100));

        $baseDigits = search_suggestions_normalize_digits(number_format($basePrice, 2, '.', ''));
        $discountedDigits = search_suggestions_normalize_digits(number_format($discountedPrice, 2, '.', ''));

        if ($baseDigits !== '' && strpos($baseDigits, $queryDigits) !== false) {
            $score += $baseDigits === $queryDigits ? 700 : 480;
        }

        if ($discountedDigits !== '' && strpos($discountedDigits, $queryDigits) !== false) {
            $score += $discountedDigits === $queryDigits ? 680 : 460;
        }
    }

    return $score;
}

function search_suggestions_service_score(array $service, string $queryText, string $queryDigits): int
{
    $score = 0;

    $title = search_suggestions_normalize_text($service['title'] ?? '');
    $description = search_suggestions_normalize_text($service['description'] ?? '');
    $serviceType = search_suggestions_normalize_text($service['serviceType'] ?? '');

    if ($queryText !== '') {
        $score += search_suggestions_score_text($title, $queryText, 1350, 990, 760);
        $score += search_suggestions_score_text($serviceType, $queryText, 980, 640, 520);
        $score += search_suggestions_score_text($description, $queryText, 0, 0, 500);
    }

    if ($queryDigits !== '') {
        $basePrice = max(0, (float) ($service['price'] ?? 0));
        $discountPercent = max(0, min(95, (int) ($service['discountPercent'] ?? 0)));
        $discountedPrice = $basePrice * (1 - ($discountPercent / 100));

        $baseDigits = search_suggestions_normalize_digits(number_format($basePrice, 2, '.', ''));
        $discountedDigits = search_suggestions_normalize_digits(number_format($discountedPrice, 2, '.', ''));

        if ($baseDigits !== '' && strpos($baseDigits, $queryDigits) !== false) {
            $score += $baseDigits === $queryDigits ? 700 : 480;
        }

        if ($discountedDigits !== '' && strpos($discountedDigits, $queryDigits) !== false) {
            $score += $discountedDigits === $queryDigits ? 680 : 460;
        }
    }

    return $score;
}

function search_suggestions_is_image_path(string $path): bool
{
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

    return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
}

function search_suggestions_service_thumbnail(array $serviceRecord): string
{
    $thumbnailCandidates = $serviceRecord['thumbnail_images'] ?? [];

    if (!is_array($thumbnailCandidates)) {
        $thumbnailCandidates = [];
    }

    foreach ($thumbnailCandidates as $thumbnailPath) {
        $normalizedPath = trim((string) $thumbnailPath);

        if ($normalizedPath === '' || !search_suggestions_is_image_path($normalizedPath)) {
            continue;
        }

        return $normalizedPath;
    }

    return 'assets/images/main_logo.png';
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    search_suggestions_respond(405, [
        'ok' => false,
        'message' => 'Method not allowed.',
        'suggestions' => [],
    ]);
}

require_once __DIR__ . '/config/products_repository.php';
require_once __DIR__ . '/config/services_packages_repository.php';

$queryRaw = trim((string) ($_GET['q'] ?? ''));

if (function_exists('mb_substr')) {
    $queryRaw = trim((string) mb_substr($queryRaw, 0, 120, 'UTF-8'));
} else {
    $queryRaw = trim((string) substr($queryRaw, 0, 120));
}

$queryText = search_suggestions_normalize_text($queryRaw);
$queryDigits = search_suggestions_normalize_digits($queryRaw);

$minimumQueryLength = 2;
$queryTextLength = function_exists('mb_strlen')
    ? (int) mb_strlen($queryText, 'UTF-8')
    : (int) strlen($queryText);
$queryDigitsLength = (int) strlen($queryDigits);
$canSearchText = $queryTextLength >= $minimumQueryLength;
$canSearchDigits = $queryDigitsLength >= $minimumQueryLength;

if (!$canSearchText) {
    $queryText = '';
}

if (!$canSearchDigits) {
    $queryDigits = '';
}

$limitRaw = (int) ($_GET['limit'] ?? 8);
$limit = max(1, min(12, $limitRaw));

$contextRaw = search_suggestions_normalize_text($_GET['context'] ?? 'customer');
$isAdminSession = isset($_SESSION['user_id']) && !isset($_SESSION['customer_id']);
$isAdminContext = $contextRaw === 'admin' && $isAdminSession;

$productRoutePath = $isAdminContext ? 'admin/products/' : 'customer-products/';
$serviceRoutePath = $isAdminContext ? 'admin/service/' : 'customer-service/';
$fallbackSearchTargetPath = $productRoutePath . ($queryRaw === '' ? '' : ('?q=' . rawurlencode($queryRaw)));

if ($queryText === '' && $queryDigits === '') {
    search_suggestions_respond(200, [
        'ok' => true,
        'query' => $queryRaw,
        'minQueryLength' => $minimumQueryLength,
        'fallbackTargetPath' => $fallbackSearchTargetPath,
        'suggestions' => [],
    ]);
}

$productsRepository = load_products_repository();
$servicesRepository = load_services_packages_repository();

if (!is_array($productsRepository)) {
    $productsRepository = [];
}

if (!is_array($servicesRepository)) {
    $servicesRepository = [];
}

$suggestions = [];

foreach ($productsRepository as $productKey => $productRecord) {
    if (!is_array($productRecord)) {
        continue;
    }

    $normalizedProductKey = trim((string) $productKey);

    if ($normalizedProductKey === '') {
        continue;
    }

    $score = search_suggestions_product_score($productRecord, $queryText, $queryDigits);

    if ($score <= 0) {
        continue;
    }

    $brandLabel = trim((string) ($productRecord['brand'] ?? ''));

    if (function_exists('normalize_product_brand')) {
        $brandLabel = normalize_product_brand($brandLabel === '' ? default_product_brand() : $brandLabel);
    }

    $nameLabel = trim((string) ($productRecord['name'] ?? ''));
    $titleLabel = trim($brandLabel . ' ' . $nameLabel);

    if ($titleLabel === '') {
        $titleLabel = strtoupper(str_replace('-', ' ', $normalizedProductKey));
    }

    $basePrice = max(0, (float) ($productRecord['price'] ?? 0));
    $discountPercent = max(0, min(95, (int) ($productRecord['discountPercent'] ?? 0)));
    $discountedPrice = $basePrice * (1 - ($discountPercent / 100));

    $description = search_suggestions_trim_description(
        (string) ($productRecord['tagline'] ?? ($productRecord['spec1'] ?? '')),
        118
    );

    $thumbnailPath = trim((string) ($productRecord['cameraImage'] ?? ''));

    if ($thumbnailPath === '') {
        $thumbnailPath = 'assets/images/main_logo.png';
    }

    $targetPath = $productRoutePath . '?product=' . rawurlencode($normalizedProductKey) . '&q=' . rawurlencode($queryRaw);

    $suggestions[] = [
        'type' => 'product',
        'typeLabel' => 'Product',
        'title' => $titleLabel,
        'description' => $description,
        'priceLabel' => search_suggestions_format_price($basePrice),
        'discountedPriceLabel' => $discountPercent > 0 ? search_suggestions_format_price($discountedPrice) : '',
        'discountPercent' => $discountPercent,
        'thumbnailPath' => $thumbnailPath,
        'targetPath' => $targetPath,
        'score' => $score,
    ];
}

$serviceTypeLabels = service_package_type_labels();

foreach ($servicesRepository as $serviceKey => $serviceRecord) {
    if (!is_array($serviceRecord)) {
        continue;
    }

    $normalizedServiceKey = normalize_service_package_key((string) $serviceKey);

    if ($normalizedServiceKey === '') {
        continue;
    }

    $score = search_suggestions_service_score($serviceRecord, $queryText, $queryDigits);

    if ($score <= 0) {
        continue;
    }

    $serviceType = normalize_service_package_type((string) ($serviceRecord['serviceType'] ?? default_service_package_type()));
    $serviceTypeLabel = (string) ($serviceTypeLabels[$serviceType] ?? 'Service');

    $titleLabel = trim((string) ($serviceRecord['title'] ?? 'PACKAGE'));

    if ($titleLabel === '') {
        $titleLabel = 'PACKAGE';
    }

    if (stripos($titleLabel, 'package') === 0) {
        $titleLabel = $serviceTypeLabel . ' ' . $titleLabel;
    }

    $description = search_suggestions_trim_description((string) ($serviceRecord['description'] ?? ''), 118);
    $basePrice = max(0, (float) ($serviceRecord['price'] ?? 0));
    $discountPercent = max(0, min(95, (int) ($serviceRecord['discountPercent'] ?? 0)));
    $discountedPrice = $basePrice * (1 - ($discountPercent / 100));

    $targetPath = $serviceRoutePath . '?package=' . rawurlencode($normalizedServiceKey);

    $suggestions[] = [
        'type' => 'service',
        'typeLabel' => 'Service',
        'title' => $titleLabel,
        'description' => $description,
        'priceLabel' => search_suggestions_format_price($basePrice),
        'discountedPriceLabel' => $discountPercent > 0 ? search_suggestions_format_price($discountedPrice) : '',
        'discountPercent' => $discountPercent,
        'thumbnailPath' => search_suggestions_service_thumbnail($serviceRecord),
        'targetPath' => $targetPath,
        'score' => $score,
    ];
}

usort(
    $suggestions,
    static function (array $left, array $right): int {
        $scoreComparison = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));

        if ($scoreComparison !== 0) {
            return $scoreComparison;
        }

        $typeOrder = ['product' => 0, 'service' => 1];
        $leftType = (string) ($left['type'] ?? 'product');
        $rightType = (string) ($right['type'] ?? 'product');
        $leftTypeOrder = $typeOrder[$leftType] ?? 2;
        $rightTypeOrder = $typeOrder[$rightType] ?? 2;
        $typeComparison = $leftTypeOrder <=> $rightTypeOrder;

        if ($typeComparison !== 0) {
            return $typeComparison;
        }

        return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
    }
);

$suggestions = array_slice($suggestions, 0, $limit);

foreach ($suggestions as &$suggestion) {
    unset($suggestion['score']);
}
unset($suggestion);

search_suggestions_respond(200, [
    'ok' => true,
    'query' => $queryRaw,
    'minQueryLength' => $minimumQueryLength,
    'fallbackTargetPath' => $fallbackSearchTargetPath,
    'suggestions' => $suggestions,
]);
