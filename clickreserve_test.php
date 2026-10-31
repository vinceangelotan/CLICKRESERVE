<?php
/*
 * TEMPORARY TEST FILE for ClickReserve algorithm testing.
 * Put this file in the creaty folder (next to product_page_customer.php),
 * open it in the browser, then DELETE it when you are done.
 *
 * The scoring code below is copied from product_page_customer.php, so it
 * produces the same results as the real "Recommendations" section.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/config/products_repository.php';
require_once __DIR__ . '/config/customer_orders_repository.php';

// ---------------- Helper functions (copied from product_page_customer.php) ----------------
function product_reco_timezone()
{
    if (function_exists('customer_order_timezone')) {
        $timezone = customer_order_timezone();

        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }
    }

    static $fallbackTimezone = null;

    if ($fallbackTimezone instanceof DateTimeZone) {
        return $fallbackTimezone;
    }

    try {
        $fallbackTimezone = new DateTimeZone('Asia/Manila');
    } catch (Throwable $error) {
        $fallbackTimezone = new DateTimeZone('UTC');
    }

    return $fallbackTimezone;
}

function product_reco_normalize_product_key($value)
{
    if (function_exists('normalize_customer_order_product_key')) {
        return normalize_customer_order_product_key($value);
    }

    $normalized = strtolower(trim((string) $value));
    $normalized = preg_replace('/[^a-z0-9-]+/', '-', $normalized);
    $normalized = trim((string) $normalized, '-');

    return $normalized;
}

function product_reco_parse_datetime($value)
{
    $raw = trim((string) $value);

    if ($raw === '') {
        return null;
    }

    if (function_exists('customer_order_parse_datetime_value')) {
        $parsed = customer_order_parse_datetime_value($raw);

        if ($parsed instanceof DateTimeImmutable) {
            return $parsed;
        }
    }

    try {
        $parsed = new DateTimeImmutable($raw, product_reco_timezone());
    } catch (Throwable $error) {
        return null;
    }

    return $parsed->setTimezone(product_reco_timezone());
}

function product_reco_extract_usage_datetime($record)
{
    if (!is_array($record)) {
        return null;
    }

    $handoverTimestamp = product_reco_parse_datetime($record['receive_handover_confirmed_at'] ?? '');

    if ($handoverTimestamp instanceof DateTimeImmutable) {
        return $handoverTimestamp;
    }

    $receiveDate = function_exists('normalize_customer_order_date')
        ? normalize_customer_order_date($record['receive_date'] ?? '')
        : trim((string) ($record['receive_date'] ?? ''));
    $receiveTime = function_exists('normalize_customer_order_time')
        ? normalize_customer_order_time($record['receive_time'] ?? '')
        : trim((string) ($record['receive_time'] ?? ''));

    if ($receiveDate !== '' && function_exists('customer_order_parse_schedule_datetime') && $receiveTime !== '') {
        $schedule = customer_order_parse_schedule_datetime($receiveDate, $receiveTime);

        if ($schedule instanceof DateTimeImmutable) {
            return $schedule;
        }
    }

    return product_reco_parse_datetime($record['created_at'] ?? '');
}

function product_reco_extract_item_product_key($item, $products, $nameLookup)
{
    if (!is_array($item)) {
        return '';
    }

    if (function_exists('customer_order_resolve_item_product_key')) {
        $resolved = product_reco_normalize_product_key(customer_order_resolve_item_product_key($item, $products, $nameLookup));

        if ($resolved !== '') {
            return $resolved;
        }
    }

    $explicit = product_reco_normalize_product_key($item['product_key'] ?? $item['productKey'] ?? '');

    if ($explicit !== '') {
        return $explicit;
    }

    if (function_exists('customer_order_extract_product_key_from_item_id')) {
        $fromItemId = product_reco_normalize_product_key(customer_order_extract_product_key_from_item_id($item['item_id'] ?? $item['itemId'] ?? ''));

        if ($fromItemId !== '') {
            return $fromItemId;
        }
    }

    $itemId = strtolower(trim((string) ($item['item_id'] ?? $item['itemId'] ?? '')));

    if (strpos($itemId, 'camera-') === 0) {
        return product_reco_normalize_product_key(substr($itemId, 7));
    }

    $nameLookupLabel = function_exists('customer_order_normalize_lookup_label')
        ? customer_order_normalize_lookup_label($item['name'] ?? '')
        : strtolower(trim((string) ($item['name'] ?? '')));

    if ($nameLookupLabel !== '' && isset($nameLookup[$nameLookupLabel])) {
        return product_reco_normalize_product_key($nameLookup[$nameLookupLabel]);
    }

    return '';
}

function product_reco_usage_counts_last_30_days($orders, $products, $nameLookup, $nowDateTime)
{
    $counts = [];

    if (!is_array($orders) || !($nowDateTime instanceof DateTimeImmutable)) {
        return $counts;
    }

    $windowStartTimestamp = $nowDateTime->sub(new DateInterval('P30D'))->getTimestamp();
    $nowTimestamp = $nowDateTime->getTimestamp();
    $trackedStatuses = ['ongoing', 'return', 'completed'];

    foreach ($orders as $record) {
        if (!is_array($record)) {
            continue;
        }

        $statusToken = function_exists('normalize_customer_order_status_token')
            ? normalize_customer_order_status_token($record['status'] ?? 'pending')
            : strtolower(trim((string) ($record['status'] ?? 'pending')));

        if (!in_array($statusToken, $trackedStatuses, true)) {
            continue;
        }

        $usageDateTime = product_reco_extract_usage_datetime($record);

        if (!$usageDateTime instanceof DateTimeImmutable) {
            continue;
        }

        $usageTimestamp = $usageDateTime->getTimestamp();

        if ($usageTimestamp < $windowStartTimestamp || $usageTimestamp > $nowTimestamp) {
            continue;
        }

        $items = is_array($record['items'] ?? null)
            ? array_values($record['items'])
            : [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $resolvedKey = product_reco_extract_item_product_key($item, $products, $nameLookup);

            if ($resolvedKey === '') {
                continue;
            }

            $qty = max(1, (int) ($item['qty'] ?? 1));

            if (!isset($counts[$resolvedKey])) {
                $counts[$resolvedKey] = 0;
            }

            $counts[$resolvedKey] += $qty;
        }
    }

    return $counts;
}

function product_reco_normalize_availability_payload($payload)
{
    $normalized = [
        'open_hour' => 8,
        'horizon_days' => 60,
        'capacities' => [],
        'intervals_by_product' => [],
    ];

    if (!is_array($payload)) {
        return $normalized;
    }

    $openHour = (int) ($payload['booking']['openHour'] ?? 8);
    $normalized['open_hour'] = max(0, min(23, $openHour));
    $horizonDays = (int) ($payload['horizonDays'] ?? 60);
    $normalized['horizon_days'] = max(30, min(1095, $horizonDays));

    $productsPayload = is_array($payload['products'] ?? null)
        ? $payload['products']
        : [];

    foreach ($productsPayload as $productKey => $productRow) {
        $normalizedKey = product_reco_normalize_product_key($productKey);

        if ($normalizedKey === '') {
            continue;
        }

        $capacity = max(0, (int) ($productRow['capacity'] ?? 0));
        $normalized['capacities'][$normalizedKey] = $capacity;
    }

    $reservations = is_array($payload['reservations'] ?? null)
        ? $payload['reservations']
        : [];

    foreach ($reservations as $reservation) {
        if (!is_array($reservation)) {
            continue;
        }

        $productKey = product_reco_normalize_product_key($reservation['productKey'] ?? '');

        if ($productKey === '') {
            continue;
        }

        $startDate = trim((string) ($reservation['startDate'] ?? ''));
        $startTime = trim((string) ($reservation['startTime'] ?? ''));
        $startTimestamp = function_exists('customer_order_schedule_timestamp')
            ? customer_order_schedule_timestamp($startDate, $startTime)
            : null;

        if (!is_int($startTimestamp)) {
            continue;
        }

        $qty = max(1, (int) ($reservation['qty'] ?? 1));
        $days = max(1, (int) ($reservation['days'] ?? 1));

        if (!isset($normalized['intervals_by_product'][$productKey])) {
            $normalized['intervals_by_product'][$productKey] = [];
        }

        $normalized['intervals_by_product'][$productKey][] = [
            'qty' => $qty,
            'start_ts' => $startTimestamp,
            'end_ts' => $startTimestamp + ($days * 86400),
        ];
    }

    foreach ($normalized['intervals_by_product'] as &$intervals) {
        usort($intervals, static function ($left, $right) {
            return ((int) ($left['start_ts'] ?? 0)) <=> ((int) ($right['start_ts'] ?? 0));
        });
    }
    unset($intervals);

    return $normalized;
}

function product_reco_occupied_qty_for_slot($intervals, $slotStartTs, $slotEndTs)
{
    return customer_order_peak_reserved_quantity($intervals, $slotStartTs, $slotEndTs);
}

function product_reco_reserved_qty_now($intervals, $timestamp)
{
    $reservedQty = 0;

    foreach ((array) $intervals as $interval) {
        if (!is_array($interval)) {
            continue;
        }

        $intervalStart = (int) ($interval['start_ts'] ?? 0);
        $intervalEnd = (int) ($interval['end_ts'] ?? 0);

        if ($timestamp < $intervalStart || $timestamp >= $intervalEnd) {
            continue;
        }

        $reservedQty += max(1, (int) ($interval['qty'] ?? 1));
    }

    return $reservedQty;
}

function product_reco_next_available_offset_days($normalizedProductKey, $availability, $dayStartDateTime, $requiredQty = 1, $requiredDays = 1)
{
    if (!($dayStartDateTime instanceof DateTimeImmutable)) {
        return null;
    }

    $capacity = max(0, (int) ($availability['capacities'][$normalizedProductKey] ?? 0));

    if ($capacity < $requiredQty) {
        return null;
    }

    $intervals = is_array($availability['intervals_by_product'][$normalizedProductKey] ?? null)
        ? $availability['intervals_by_product'][$normalizedProductKey]
        : [];
    $openHour = max(0, min(23, (int) ($availability['open_hour'] ?? 8)));
    $horizonDays = max(1, (int) ($availability['horizon_days'] ?? 60));

    for ($offset = 0; $offset <= $horizonDays; $offset++) {
        $slotDateTime = $dayStartDateTime->modify('+' . $offset . ' day')->setTime($openHour, 0);
        $slotStartTs = (int) $slotDateTime->getTimestamp();
        $slotEndTs = $slotStartTs + (max(1, (int) $requiredDays) * 86400);
        $occupiedQty = product_reco_occupied_qty_for_slot($intervals, $slotStartTs, $slotEndTs);

        if (($occupiedQty + $requiredQty) <= $capacity) {
            return $offset;
        }
    }

    return null;
}

function product_reco_effective_price($product)
{
    if (!is_array($product)) {
        return 0.0;
    }

    $basePrice = (float) ($product['price'] ?? 0);

    if (!is_finite($basePrice) || $basePrice < 0) {
        $basePrice = 0;
    }

    $discountPercent = max(0, min(95, (int) ($product['discountPercent'] ?? 0)));
    $effectivePrice = $basePrice * (1 - ($discountPercent / 100));

    if (!is_finite($effectivePrice) || $effectivePrice < 0) {
        return 0.0;
    }

    return $effectivePrice;
}

// ---------------- Scoring (copied from product_page_customer.php) ----------------
function clickreserve_test_rank($productKey, $products)
{
$selectedProduct = $products[$productKey];
$selectedBrand = normalize_product_brand($selectedProduct['brand'] ?? 'Canon');
$selectedBrandValue = product_brand_slug($selectedBrand);
$selectedSkillLevels = normalize_product_skill_levels(
    $selectedProduct['skillLevels'] ?? ($selectedProduct['skillLevel'] ?? default_product_skill_level())
);
$selectedCategory = normalize_product_category($selectedProduct['category'] ?? default_product_category());

    $recommendationNowDateTime = function_exists('customer_order_datetime_from_timestamp')
        ? customer_order_datetime_from_timestamp()
        : (new DateTimeImmutable('now', product_reco_timezone()));
    $recommendationOrders = function_exists('load_customer_orders_repository')
        ? load_customer_orders_repository()
        : [];
    $recommendationNameLookup = function_exists('customer_order_product_name_lookup_map')
        ? customer_order_product_name_lookup_map($products)
        : [];
    $recommendationUsageCounts = product_reco_usage_counts_last_30_days(
        $recommendationOrders,
        $products,
        $recommendationNameLookup,
        $recommendationNowDateTime
    );
    $recommendationAvailabilityPayload = function_exists('customer_order_build_equipment_availability_payload')
        ? customer_order_build_equipment_availability_payload(['horizon_days' => 60])
        : [];
    $recommendationAvailability = product_reco_normalize_availability_payload($recommendationAvailabilityPayload);

    $selectedNormalizedKey = product_reco_normalize_product_key($productKey);
    $selectedEffectivePrice = product_reco_effective_price($selectedProduct);
    $selectedCategorySlug = product_category_slug($selectedCategory);
    $selectedBrandSlug = product_brand_slug($selectedBrand);
    $selectedSkillLevels = normalize_product_skill_levels(
        $selectedProduct['skillLevels'] ?? ($selectedProduct['skillLevel'] ?? default_product_skill_level())
    );
    $recommendationDayStart = $recommendationNowDateTime->setTime(0, 0);
    $recommendationNowTimestamp = (int) $recommendationNowDateTime->getTimestamp();

    $candidateMetrics = [];
    $maxUsageCount = 0;

    foreach ($products as $candidateKey => $candidateProduct) {
        if (!is_array($candidateProduct)) {
            continue;
        }

        $normalizedCandidateKey = product_reco_normalize_product_key($candidateKey);

        if ($normalizedCandidateKey === '' || $normalizedCandidateKey === $selectedNormalizedKey) {
            continue;
        }

        $candidatePrice = product_reco_effective_price($candidateProduct);
        $priceGapAbs = abs($candidatePrice - $selectedEffectivePrice);
        $priceGapDenominator = max(1.0, $selectedEffectivePrice, $candidatePrice);
        $priceGapRatio = $priceGapAbs / $priceGapDenominator;
        $priceProximityScore = max(0.0, 1.0 - min(1.0, $priceGapRatio));

        $candidateCategory = normalize_product_category($candidateProduct['category'] ?? default_product_category());
        $candidateCategorySlug = product_category_slug($candidateCategory);
        $categoryScore = $candidateCategorySlug === $selectedCategorySlug ? 1.0 : 0.0;

        $candidateBrand = normalize_product_brand($candidateProduct['brand'] ?? default_product_brand());
        $brandScore = product_brand_slug($candidateBrand) === $selectedBrandSlug ? 1.0 : 0.0;

        $candidateSkillLevels = normalize_product_skill_levels(
            $candidateProduct['skillLevels'] ?? ($candidateProduct['skillLevel'] ?? default_product_skill_level())
        );
        $skillOverlapScore = count(array_intersect($selectedSkillLevels, $candidateSkillLevels)) > 0 ? 1.0 : 0.0;

        $usageCount = max(0, (int) ($recommendationUsageCounts[$normalizedCandidateKey] ?? 0));
        $maxUsageCount = max($maxUsageCount, $usageCount);

        $capacity = max(0, (int) ($recommendationAvailability['capacities'][$normalizedCandidateKey] ?? 0));
        $intervals = is_array($recommendationAvailability['intervals_by_product'][$normalizedCandidateKey] ?? null)
            ? $recommendationAvailability['intervals_by_product'][$normalizedCandidateKey]
            : [];
        $reservedNow = product_reco_reserved_qty_now($intervals, $recommendationNowTimestamp);
        $unusedUnitsNow = max(0, $capacity - $reservedNow);
        $unusedRatio = $capacity > 0 ? min(1.0, ((float) $unusedUnitsNow / (float) $capacity)) : 0.0;
        $nextAvailableOffsetDays = product_reco_next_available_offset_days(
            $normalizedCandidateKey,
            $recommendationAvailability,
            $recommendationDayStart,
            1,
            1
        );

        $availabilityScore = $nextAvailableOffsetDays === null
            ? 0.0
            : max(0.0, 1.0 - (min(30, (int) $nextAvailableOffsetDays) / 30.0));

        $candidateMetrics[] = [
            'key' => (string) $candidateKey,
            'display_name' => product_display_name($candidateProduct),
            'usage_count' => $usageCount,
            'unused_ratio' => $unusedRatio,
            'price_gap_abs' => $priceGapAbs,
            'price_score' => $priceProximityScore,
            'category_score' => $categoryScore,
            'availability_score' => $availabilityScore,
            'next_available_days' => is_int($nextAvailableOffsetDays) ? $nextAvailableOffsetDays : null,
            'skill_score' => $skillOverlapScore,
            'brand_score' => $brandScore,
        ];
    }

    foreach ($candidateMetrics as &$candidateMetric) {
        $historicalUnusedScore = $maxUsageCount > 0
            ? max(0.0, 1.0 - min(1.0, ((float) $candidateMetric['usage_count'] / (float) $maxUsageCount)))
            : 1.0;
        $unusedPriorityScore = (0.65 * (float) $candidateMetric['unused_ratio']) + (0.35 * $historicalUnusedScore);
        $totalScore =
            (0.38 * $unusedPriorityScore)
            + (0.22 * (float) $candidateMetric['price_score'])
            + (0.20 * (float) $candidateMetric['category_score'])
            + (0.16 * (float) $candidateMetric['availability_score'])
            + (0.02 * (float) $candidateMetric['skill_score'])
            + (0.02 * (float) $candidateMetric['brand_score']);

        $candidateMetric['score'] = max(0.0, $totalScore);
    }
    unset($candidateMetric);

    usort($candidateMetrics, static function ($left, $right) {
        $scoreComparison = ((float) ($right['score'] ?? 0.0)) <=> ((float) ($left['score'] ?? 0.0));

        if ($scoreComparison !== 0) {
            return $scoreComparison;
        }

        $leftOffset = is_int($left['next_available_days'] ?? null)
            ? (int) $left['next_available_days']
            : PHP_INT_MAX;
        $rightOffset = is_int($right['next_available_days'] ?? null)
            ? (int) $right['next_available_days']
            : PHP_INT_MAX;
        $availabilityComparison = $leftOffset <=> $rightOffset;

        if ($availabilityComparison !== 0) {
            return $availabilityComparison;
        }

        $usageComparison = ((int) ($left['usage_count'] ?? 0)) <=> ((int) ($right['usage_count'] ?? 0));

        if ($usageComparison !== 0) {
            return $usageComparison;
        }

        $priceGapComparison = ((float) ($left['price_gap_abs'] ?? 0.0)) <=> ((float) ($right['price_gap_abs'] ?? 0.0));

        if ($priceGapComparison !== 0) {
            return $priceGapComparison;
        }

        return strcmp((string) ($left['display_name'] ?? ''), (string) ($right['display_name'] ?? ''));
    });

    return [$candidateMetrics, $recommendationUsageCounts];
}

// ---------------- Run the tests ----------------
$products = load_products_repository();

if (!is_array($products) || !$products) {
    echo "No products found.\n";
    exit;
}

$requested = trim((string) ($_GET['product'] ?? ''));
$testKeys = $requested !== '' ? [$requested] : ['canon-700d', 'sony-zv-e10', 'canon-1200d', 'nikon-d60'];

echo "CLICKRESERVE ALGORITHM TEST (run at " . date('Y-m-d H:i:s') . ")\n";
echo str_repeat('=', 100) . "\n";

$usageToPrint = [];

foreach ($testKeys as $viewKey) {
    if (!isset($products[$viewKey])) {
        echo "\nProduct not found: {$viewKey}\n";
        continue;
    }

    [$ranking, $usageCounts] = clickreserve_test_rank($viewKey, $products);
    $usageToPrint = $usageCounts;

    echo "\nVIEWING: {$viewKey}\n";
    echo str_repeat('-', 100) . "\n";
    echo "Rank  Camera                        Score    FreeNow Usage  Price   Cat  Avail   NextDays\n";

    foreach ($ranking as $i => $m) {
        printf(
            "%-5d %-29s %-8.4f %-7.3f %-6d %-7.3f %-4d %-7.3f %s%s\n",
            $i + 1,
            $m['key'],
            $m['score'],
            $m['unused_ratio'],
            $m['usage_count'],
            $m['price_score'],
            $m['category_score'],
            $m['availability_score'],
            $m['next_available_days'] ?? 'none',
            $i < 3 ? '   <== TOP 3 (shown to customer)' : ''
        );
    }
}

echo "\n" . str_repeat('=', 100) . "\n";
echo "30-DAY USAGE COUNTS (Ongoing / For Return / Completed orders only)\n";
echo str_repeat('-', 100) . "\n";

if (!$usageToPrint) {
    echo "(no orders counted)\n";
}

arsort($usageToPrint);
foreach ($usageToPrint as $key => $count) {
    echo str_pad((string) $key, 32) . $count . "\n";
}

echo "\nMANUAL RECOMMENDATION LISTS (fallback / admin view)\n";
echo str_repeat('-', 100) . "\n";
foreach ($testKeys as $viewKey) {
    if (isset($products[$viewKey])) {
        echo str_pad($viewKey, 20) . implode(', ', (array) ($products[$viewKey]['recommendations'] ?? [])) . "\n";
    }
}
