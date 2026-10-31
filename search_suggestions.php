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

/**
 * True when every word of the query appears somewhere in $haystack (any order).
 * Lets "canon 700d" match a product whose brand is "Canon" and name is "700D".
 */
function search_suggestions_all_words_match(string $haystack, string $query): bool
{
    if ($haystack === '' || $query === '') {
        return false;
    }

    foreach (explode(' ', $query) as $word) {
        if ($word !== '' && strpos($haystack, $word) === false) {
            return false;
        }
    }

    return true;
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

        // The card title customers see is "<brand> <name>", so match the phrase against that too.
        $score += search_suggestions_score_text(trim($brand . ' ' . $name), $queryText, 1400, 1000, 780);

        // Multi-word queries: every word may sit in a different field or in a different order.
        if (strpos($queryText, ' ') !== false) {
            if (search_suggestions_all_words_match(trim($brand . ' ' . $name), $queryText)) {
                $score += 900;
            } elseif (search_suggestions_all_words_match(trim($brand . ' ' . $name . ' ' . $tagline . ' ' . $specOne . ' ' . $specTwo), $queryText)) {
                $score += 450;
            }
        }
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

        if (strpos($queryText, ' ') !== false
            && search_suggestions_all_words_match(trim($title . ' ' . $serviceType . ' ' . $description), $queryText)) {
            $score += 450;
        }
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

/* ------------------------------------------------------------------ availability + similar cameras */

function search_suggestions_load_availability_context(): array
{
    $products = customer_order_load_products_for_availability();
    $inventory = customer_order_load_inventory_for_availability();
    $nameLookup = customer_order_product_name_lookup_map($products);
    $orders = load_customer_orders_repository();

    return [
        'now' => time(),
        'products' => $products,
        'inventory' => $inventory,
        'capacity' => customer_order_product_capacity_map($products, $inventory),
        'intervals' => customer_order_group_intervals_by_product(
            customer_order_build_camera_reservation_intervals($orders, $products, $nameLookup)
        ),
    ];
}

function search_suggestions_format_timestamp(int $timestamp): string
{
    return customer_order_local_datetime_format_from_timestamp($timestamp, 'M j, g:i A');
}

/**
 * Customer-facing status for one camera model: no customer data, only counts and dates.
 *
 * available   at least one usable unit is free right now
 * rented      every unit is out on an ongoing / for-return rental
 * reserved    every unit is held by an approved reservation whose window has started
 * unavailable no usable unit (maintenance / broken / none in stock)
 */
function search_suggestions_product_status(string $normalizedKey, array $context): array
{
    $now = (int) $context['now'];
    $capacity = max(0, (int) ($context['capacity'][$normalizedKey] ?? 0));
    $intervals = array_values(array_filter(
        (array) ($context['intervals'][$normalizedKey] ?? []),
        static function ($interval) use ($now) {
            return is_array($interval) && (int) ($interval['end_ts'] ?? 0) > $now;
        }
    ));

    if ($capacity <= 0) {
        return ['token' => 'unavailable', 'label' => 'Unavailable', 'detail' => 'Not rentable at the moment.'];
    }

    $occupiedNow = (int) customer_order_peak_reserved_quantity($intervals, $now, $now + 60);
    $freeNow = max(0, $capacity - $occupiedNow);

    $activeNow = array_values(array_filter($intervals, static function ($interval) use ($now) {
        return (int) $interval['start_ts'] <= $now;
    }));

    $nextReturn = null;
    foreach ($activeNow as $interval) {
        $end = (int) $interval['end_ts'];
        $nextReturn = $nextReturn === null ? $end : min($nextReturn, $end);
    }
    $returnKnown = $nextReturn !== null && $nextReturn < $now + 365 * 86400;

    if ($freeNow > 0) {
        $parts = [];

        if ($capacity > 1) {
            $parts[] = $freeNow . ' of ' . $capacity . ' units free now';
        }

        if ($occupiedNow > 0) {
            $parts[] = $returnKnown ? 'next return ' . search_suggestions_format_timestamp($nextReturn) : 'return date to be confirmed';
        }

        // Soonest upcoming reservation window, so customers know when it is not free.
        $upcoming = null;
        foreach ($intervals as $interval) {
            if ((int) $interval['start_ts'] > $now && ($upcoming === null || (int) $interval['start_ts'] < (int) $upcoming['start_ts'])) {
                $upcoming = $interval;
            }
        }

        if ($upcoming !== null) {
            $held = (int) customer_order_peak_reserved_quantity($intervals, (int) $upcoming['start_ts'], (int) $upcoming['start_ts'] + 60);
            $window = search_suggestions_format_timestamp((int) $upcoming['start_ts'])
                . ' - ' . ((int) $upcoming['end_ts'] < $now + 365 * 86400 ? search_suggestions_format_timestamp((int) $upcoming['end_ts']) : 'open');
            $parts[] = ($held >= $capacity ? 'fully booked ' : max(1, $held) . ($held === 1 ? ' unit' : ' units') . ' reserved ') . $window;
        }

        return ['token' => 'available', 'label' => 'Available', 'detail' => $parts ? ucfirst(implode(' · ', $parts)) : 'Ready to rent.'];
    }

    // Fully out right now: find the first moment a unit is free again.
    $availableAt = null;
    $candidates = [];
    foreach ($activeNow as $interval) {
        $candidates[] = (int) $interval['end_ts'];
    }
    sort($candidates, SORT_NUMERIC);

    foreach ($candidates as $candidate) {
        if ($candidate >= $now + 365 * 86400) {
            break;
        }

        if ($capacity - (int) customer_order_peak_reserved_quantity($intervals, $candidate, $candidate + 60) > 0) {
            $availableAt = $candidate;
            break;
        }
    }

    $isRented = false;
    foreach ($activeNow as $interval) {
        if (in_array((string) ($interval['status_token'] ?? ''), ['ongoing', 'return'], true)) {
            $isRented = true;
            break;
        }
    }

    if ($availableAt === null) {
        return [
            'token' => $isRented ? 'rented' : 'reserved',
            'label' => $isRented ? 'Rented out' : 'Reserved',
            'detail' => 'Return date to be confirmed.',
        ];
    }

    return [
        'token' => $isRented ? 'rented' : 'reserved',
        'label' => $isRented ? 'Rented out' : 'Reserved',
        'detail' => ($isRented ? 'Expected back ' : 'Available again ') . search_suggestions_format_timestamp($availableAt),
    ];
}

function search_suggestions_spec_tokens(array $product): array
{
    $text = search_suggestions_normalize_text(
        ($product['tagline'] ?? '') . ' ' . ($product['spec1'] ?? '') . ' ' . ($product['spec2'] ?? '')
    );
    $text = (string) preg_replace('/aps[\s-]?c/', 'apsc', $text);
    $text = (string) preg_replace('/full[\s-]?hd/', 'fullhd', $text);
    $stopWords = ['the', 'and', 'with', 'for', 'of', 'to', 'up', 'at', 'an', 'in', 'on', 'camera', 'sensor', 'video', 'recording', 'range', 'expandable', 'fps'];
    $tokens = [];

    foreach ((array) preg_split('/[^a-z0-9.]+/', $text) as $token) {
        $token = trim((string) $token, '.');

        if (strlen($token) >= 2 && !in_array($token, $stopWords, true)) {
            $tokens[$token] = true;
        }
    }

    return array_keys($tokens);
}

function search_suggestions_pretty_token(string $token): string
{
    if ($token === 'apsc') {
        return 'APS-C';
    }

    if ($token === 'fullhd') {
        return 'Full HD';
    }

    return preg_match('/[0-9]/', $token) || in_array($token, ['cmos', 'iso'], true) ? strtoupper($token) : ucfirst($token);
}

/**
 * Other cameras with similar specs to $baseKey (shared spec words, category, skill level, price band).
 * Cameras with no usable unit are skipped; fully rented ones stay, with their expected return date.
 */
function search_suggestions_similar_products(string $baseKey, array $context, string $routePath, int $limit = 3): array
{
    $products = $context['products'];
    $base = null;
    $baseNormalized = normalize_customer_order_product_key($baseKey);

    foreach ($products as $productKey => $product) {
        if (is_array($product) && normalize_customer_order_product_key((string) $productKey) === $baseNormalized) {
            $base = $product;
            break;
        }
    }

    if (!is_array($base)) {
        return [];
    }

    $baseTokens = search_suggestions_spec_tokens($base);
    $baseCategory = search_suggestions_normalize_text($base['category'] ?? '');
    $baseBrand = search_suggestions_normalize_text($base['brand'] ?? '');
    $basePrice = (float) preg_replace('/[^0-9.]/', '', (string) ($base['price'] ?? '0'));
    $baseSkills = function_exists('normalize_product_skill_levels')
        ? (array) normalize_product_skill_levels($base['skillLevels'] ?? ($base['skillLevel'] ?? (function_exists('default_product_skill_level') ? default_product_skill_level() : 'Beginner')))
        : [];
    $candidates = [];

    foreach ($products as $productKey => $product) {
        $candidateKey = normalize_customer_order_product_key((string) $productKey);

        if (!is_array($product) || $candidateKey === '' || $candidateKey === $baseNormalized) {
            continue;
        }

        if ((int) ($context['capacity'][$candidateKey] ?? 0) <= 0) {
            continue;
        }

        $shared = array_values(array_intersect($baseTokens, search_suggestions_spec_tokens($product)));
        $sameCategory = $baseCategory !== '' && search_suggestions_normalize_text($product['category'] ?? '') === $baseCategory;
        $score = min(60, count($shared) * 12) + ($sameCategory ? 15 : 0);
        $score += search_suggestions_normalize_text($product['brand'] ?? '') === $baseBrand ? 8 : 0;

        if ($baseSkills) {
            $candidateSkills = function_exists('normalize_product_skill_levels')
                ? (array) normalize_product_skill_levels($product['skillLevels'] ?? ($product['skillLevel'] ?? (function_exists('default_product_skill_level') ? default_product_skill_level() : 'Beginner')))
                : [];
            $score += array_intersect($baseSkills, $candidateSkills) ? 15 : 0;
        }

        $candidatePrice = (float) preg_replace('/[^0-9.]/', '', (string) ($product['price'] ?? '0'));
        if ($basePrice > 0 && $candidatePrice > 0) {
            $score += (int) round(15 * max(0, 1 - abs($candidatePrice - $basePrice) / $basePrice));
        }

        $status = search_suggestions_product_status($candidateKey, $context);
        $score += $status['token'] === 'available' ? 10 : 0;

        if ($score < 30 || (!$shared && !$sameCategory)) {
            continue;
        }

        $brandLabel = trim((string) ($product['brand'] ?? ''));
        if (function_exists('normalize_product_brand')) {
            $brandLabel = normalize_product_brand($brandLabel === '' ? default_product_brand() : $brandLabel);
        }

        $basePriceValue = max(0, $candidatePrice);
        $discountPercent = max(0, min(95, (int) ($product['discountPercent'] ?? 0)));
        $thumbnailPath = trim((string) ($product['cameraImage'] ?? ''));

        $candidates[] = [
            'type' => 'product',
            'section' => 'similar',
            'typeLabel' => 'Similar',
            'title' => trim($brandLabel . ' ' . trim((string) ($product['name'] ?? ''))),
            'description' => $shared
                ? 'Similar specs: ' . implode(', ', array_map('search_suggestions_pretty_token', array_slice($shared, 0, 3)))
                : 'Same type of camera',
            'priceLabel' => search_suggestions_format_price($basePriceValue),
            'discountedPriceLabel' => $discountPercent > 0 ? search_suggestions_format_price($basePriceValue * (1 - ($discountPercent / 100))) : '',
            'discountPercent' => $discountPercent,
            'thumbnailPath' => $thumbnailPath !== '' ? $thumbnailPath : 'assets/images/main_logo.png',
            'targetPath' => $routePath . '?product=' . rawurlencode((string) $productKey),
            'status' => $status,
            'score' => $score,
        ];
    }

    usort($candidates, static function (array $left, array $right): int {
        return ($right['score'] <=> $left['score']) ?: strcasecmp($left['title'], $right['title']);
    });

    return array_slice($candidates, 0, max(1, $limit));
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
require_once __DIR__ . '/config/customer_orders_repository.php';

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
$productScoreList = [];

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

    $productScoreList[] = ['key' => $normalizedProductKey, 'score' => $score];

    $suggestions[] = [
        '_productKey' => $normalizedProductKey,
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

// Availability status (and similar cameras) are an extra: if they cannot be loaded, plain suggestions still work.
$similarSuggestions = [];

try {
    $hasProductSuggestion = false;

    foreach ($suggestions as $suggestionRow) {
        if (isset($suggestionRow['_productKey'])) {
            $hasProductSuggestion = true;
            break;
        }
    }

    if ($hasProductSuggestion) {
        $availabilityContext = search_suggestions_load_availability_context();

        foreach ($suggestions as &$suggestionRef) {
            if (isset($suggestionRef['_productKey'])) {
                $suggestionRef['status'] = search_suggestions_product_status(
                    normalize_customer_order_product_key($suggestionRef['_productKey']),
                    $availabilityContext
                );
            }
        }
        unset($suggestionRef);

        // Similar cameras only when the search clearly points at ONE camera.
        usort($productScoreList, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score'];
        });
        $topProduct = $productScoreList[0] ?? null;
        $secondProduct = $productScoreList[1] ?? null;

        if ($topProduct !== null && $topProduct['score'] >= 900 && ($secondProduct === null || $topProduct['score'] >= $secondProduct['score'] * 1.4)) {
            $similarSuggestions = search_suggestions_similar_products($topProduct['key'], $availabilityContext, $productRoutePath, 3);
        }
    }
} catch (Throwable $availabilityError) {
    $similarSuggestions = [];
}

$suggestions = array_merge($suggestions, $similarSuggestions);

foreach ($suggestions as &$suggestion) {
    unset($suggestion['score'], $suggestion['_productKey']);
}
unset($suggestion);

search_suggestions_respond(200, [
    'ok' => true,
    'query' => $queryRaw,
    'minQueryLength' => $minimumQueryLength,
    'fallbackTargetPath' => $fallbackSearchTargetPath,
    'suggestions' => $suggestions,
]);
