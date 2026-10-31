<?php
/**
 * Equipment auto-recommendation.
 *
 * Builds on the existing availability engine in customer_orders_repository.php, so it already
 * accounts for every reservation: customer rentals AND event/service-package bookings
 * (customer_order_extract_camera_item_requirements expands packages into camera keys).
 */

require_once __DIR__ . '/customer_orders_repository.php';
require_once __DIR__ . '/products_repository.php';

/** Skill levels a customer may have. 'Unsure' is treated as Beginner for safety. */
function equipment_recommendation_effective_skill_level($skillLevel)
{
    $value = trim((string) $skillLevel);

    if (strcasecmp($value, 'Professional') === 0) {
        return 'Professional';
    }

    return 'Beginner'; // Beginner and Unsure
}

function equipment_recommendation_product_skill_levels($product)
{
    return normalize_product_skill_levels(
        $product['skillLevels'] ?? ($product['skillLevel'] ?? default_product_skill_level())
    );
}

function equipment_recommendation_price($product)
{
    return (float) preg_replace('/[^0-9.]/', '', (string) ($product['price'] ?? '0'));
}

/**
 * Peak simultaneous units already reserved for a product across [start, end).
 */
function equipment_recommendation_free_units($productKey, $startTs, $endTs, $capacityMap, $intervalsByProduct)
{
    $capacity = max(0, (int) ($capacityMap[$productKey] ?? 0));
    $intervals = isset($intervalsByProduct[$productKey]) && is_array($intervalsByProduct[$productKey])
        ? $intervalsByProduct[$productKey]
        : [];
    $reserved = customer_order_peak_reserved_quantity($intervals, $startTs, $endTs, '');

    return max(0, $capacity - (int) $reserved);
}

/**
 * @param array  $requestedItems  cart items (same shape as order payload items)
 * @param string $skillLevel      Beginner | Professional | Unsure
 * @param array  $schedule        receiveDate, receiveTime, returnDate, returnTime
 * @param int    $limit           alternatives per unavailable/unsuitable item
 * @return array[] one entry per requested camera: status, message, alternatives[]
 */
function build_equipment_recommendations($requestedItems, $skillLevel, $schedule, $limit = 3)
{
    $products = customer_order_load_products_for_availability();
    $inventory = customer_order_load_inventory_for_availability();
    $nameLookup = customer_order_product_name_lookup_map($products);
    $orders = load_customer_orders_repository();

    $startTs = customer_order_schedule_timestamp($schedule['receiveDate'] ?? '', $schedule['receiveTime'] ?? '');
    $endTs = customer_order_schedule_timestamp($schedule['returnDate'] ?? '', $schedule['returnTime'] ?? '');

    if ($startTs === null) {
        return [];
    }

    if ($endTs === null || $endTs <= $startTs) {
        $endTs = $startTs + 86400;
    }

    $effectiveSkill = equipment_recommendation_effective_skill_level($skillLevel);
    $capacityMap = customer_order_product_capacity_map($products, $inventory);
    $intervals = customer_order_build_camera_reservation_intervals($orders, $products, $nameLookup);
    $intervalsByProduct = customer_order_group_intervals_by_product($intervals);
    $requirements = customer_order_extract_camera_item_requirements($requestedItems, $products, $nameLookup);

    $results = [];

    foreach ($requirements as $requestedKey => $requirement) {
        $requestedProduct = $products[$requestedKey] ?? null;
        if (!is_array($requestedProduct)) {
            continue;
        }

        $neededQty = max(1, (int) ($requirement['qty'] ?? 1));
        $freeUnits = equipment_recommendation_free_units($requestedKey, $startTs, $endTs, $capacityMap, $intervalsByProduct);
        $isAvailable = $freeUnits >= $neededQty;
        $skillSuitable = in_array($effectiveSkill, equipment_recommendation_product_skill_levels($requestedProduct), true);
        $label = customer_order_product_label_by_key($products, $requestedKey);

        if ($isAvailable && $skillSuitable) {
            $results[] = [
                'productKey' => $requestedKey,
                'name' => $label,
                'status' => 'ok',
                'message' => '',
                'alternatives' => [],
            ];
            continue;
        }

        $reasons = [];
        if (!$isAvailable) {
            $reasons[] = 'not available for your selected schedule';
        }
        if (!$skillSuitable) {
            $reasons[] = $effectiveSkill === 'Beginner'
                ? 'better suited to experienced users'
                : 'listed for a different experience level';
        }

        $requestedPrice = equipment_recommendation_price($requestedProduct);
        $requestedCategory = strtolower(trim((string) ($requestedProduct['category'] ?? '')));
        $requestedBrand = strtolower(trim((string) ($requestedProduct['brand'] ?? '')));
        $alreadySelected = array_fill_keys(array_keys($requirements), true);
        $candidates = [];

        foreach ($products as $candidateKey => $candidate) {
            if (!is_string($candidateKey) || !is_array($candidate) || isset($alreadySelected[$candidateKey])) {
                continue;
            }

            // Hard rule: must be free for the whole requested interval.
            $candidateFree = equipment_recommendation_free_units($candidateKey, $startTs, $endTs, $capacityMap, $intervalsByProduct);
            if ($candidateFree < $neededQty) {
                continue;
            }

            $candidateSkills = equipment_recommendation_product_skill_levels($candidate);
            $skillMatch = in_array($effectiveSkill, $candidateSkills, true);

            $score = 0;
            $score += $skillMatch ? 40 : 0;
            $score += strtolower(trim((string) ($candidate['category'] ?? ''))) === $requestedCategory ? 25 : 0;
            $score += strtolower(trim((string) ($candidate['brand'] ?? ''))) === $requestedBrand ? 10 : 0;

            $candidatePrice = equipment_recommendation_price($candidate);
            if ($requestedPrice > 0 && $candidatePrice > 0) {
                $score += (int) round(15 * max(0, 1 - abs($candidatePrice - $requestedPrice) / $requestedPrice));
            }

            $score += min(10, $candidateFree * 2); // more spare units = safer pick

            if ($score <= 0 || !$skillMatch) {
                continue; // never recommend something unsuitable for the customer
            }

            $candidates[] = [
                'productKey' => $candidateKey,
                'name' => customer_order_product_label_by_key($products, $candidateKey),
                'price' => (string) ($candidate['price'] ?? ''),
                'image' => (string) ($candidate['image'] ?? ''),
                'availableUnits' => $candidateFree,
                'score' => $score,
            ];
        }

        usort($candidates, function ($a, $b) {
            return $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']);
        });

        $results[] = [
            'productKey' => $requestedKey,
            'name' => $label,
            'status' => $isAvailable ? 'unsuitable' : 'unavailable',
            'message' => $label . ' is ' . implode(' and ', $reasons) . '.',
            'alternatives' => array_slice($candidates, 0, max(1, (int) $limit)),
        ];
    }

    return $results;
}
