<?php

/**
 * Unit assignment history + rotation helpers for the admin dashboard.
 *
 *   config/unit_assignment_log.json   one row per change of the physical unit(s) on an order item
 *
 * Assignment itself still happens in customer_orders_repository.php
 * (customer_order_assign_in_use_units_to_orders_and_inventory). This file only
 * records those changes and builds the "Equipment Rotation & Recommendations" card.
 * Functions here call helpers from customer_orders_repository.php / equipment_inventory_repository.php
 * at run time, so load order does not matter.
 */

function unit_assignment_log_path()
{
    return __DIR__ . '/unit_assignment_log.json';
}

function unit_assignment_log_load()
{
    $path = unit_assignment_log_path();

    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? array_values($decoded) : [];
}

function unit_assignment_log_save($rows)
{
    $rows = array_slice(array_values((array) $rows), -500);
    $encoded = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        return false;
    }

    return file_put_contents(unit_assignment_log_path(), $encoded . PHP_EOL, LOCK_EX) !== false;
}

function unit_assignment_log_normalize_serials($value)
{
    $serials = [];

    foreach ((array) $value as $serial) {
        $serials[] = (int) $serial;
    }

    sort($serials, SORT_NUMERIC);

    return array_values(array_unique($serials));
}

/**
 * Appends one history row. Skips a row identical to the latest row of the same order + model,
 * so a derived change that is re-detected before it is persisted is never logged twice.
 */
function unit_assignment_log_append($entry)
{
    if (!is_array($entry)) {
        return false;
    }

    $entry['from'] = unit_assignment_log_normalize_serials($entry['from'] ?? []);
    $entry['to'] = unit_assignment_log_normalize_serials($entry['to'] ?? []);

    if ($entry['from'] === $entry['to']) {
        return true;
    }

    $rows = unit_assignment_log_load();

    for ($index = count($rows) - 1; $index >= 0; $index--) {
        $row = $rows[$index];

        if (!is_array($row)
            || (string) ($row['orderId'] ?? '') !== (string) ($entry['orderId'] ?? '')
            || (string) ($row['productKey'] ?? '') !== (string) ($entry['productKey'] ?? '')) {
            continue;
        }

        if (unit_assignment_log_normalize_serials($row['from'] ?? []) === $entry['from']
            && unit_assignment_log_normalize_serials($row['to'] ?? []) === $entry['to']) {
            return true;
        }

        break;
    }

    $entry['id'] = 'ua-' . date('Ymd-His') . '-' . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $entry['at'] = gmdate('c');
    $entry['type'] = (string) ($entry['type'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
    $entry['by'] = substr(trim((string) ($entry['by'] ?? 'system')), 0, 64);
    $rows[] = $entry;

    return unit_assignment_log_save($rows);
}

/** Called by the automatic assignment engine whenever it changes the unit(s) on an order item. */
function unit_assignment_log_record_auto_change($orderId, $productKey, $fromSerials, $toSerials)
{
    try {
        unit_assignment_log_append([
            'orderId' => (string) $orderId,
            'productKey' => (string) $productKey,
            'from' => $fromSerials,
            'to' => $toSerials,
            'type' => 'auto',
            'by' => 'system',
        ]);
    } catch (Throwable $error) {
        // History is best-effort; it must never block a booking from loading or saving.
    }
}

function unit_rotation_unit_label($inventory, $products, $productKey, $serial)
{
    $model = isset($products[$productKey]) && is_array($products[$productKey]) && function_exists('equipment_model_label_from_product')
        ? equipment_model_label_from_product($products[$productKey])
        : (string) $productKey;
    $fallback = function_exists('equipment_unit_identifier') ? equipment_unit_identifier($model, (int) $serial) : $model . ' #' . (int) $serial;

    foreach ((array) ($inventory[$productKey]['units'] ?? []) as $unit) {
        if (is_array($unit) && (int) ($unit['serial'] ?? -1) === (int) $serial) {
            return function_exists('equipment_unit_display_label')
                ? equipment_unit_display_label($unit['serialNumber'] ?? '', $fallback)
                : $fallback;
        }
    }

    return $fallback;
}

function unit_rotation_serials_label($inventory, $products, $productKey, $serials)
{
    $labels = [];

    foreach (unit_assignment_log_normalize_serials($serials) as $serial) {
        $labels[] = unit_rotation_unit_label($inventory, $products, $productKey, $serial);
    }

    return $labels ? implode(', ', $labels) : 'none';
}

/**
 * One row per item of every Approved / Ongoing / For-return order, with the units it holds,
 * what is wrong (if anything) and which free units could be used instead.
 *
 * A unit is "eligible" when it is usable (Available/In use counting rules of the booking engine,
 * so Maintenance / Broken / Returned units are excluded) and not held by any other active order item.
 * That is the same rule the automatic assignment uses, so a manual choice is never undone by it.
 * Candidates are sorted least-used first (rotation), then by serial.
 */
function unit_rotation_build_rows($orders, $products, $inventory, $nameLookup)
{
    $inventoryByKey = [];

    foreach ((array) $inventory as $inventoryKey => $entry) {
        if (is_string($inventoryKey) && is_array($entry)) {
            $inventoryByKey[normalize_customer_order_product_key($inventoryKey)] = ['key' => $inventoryKey, 'units' => array_values((array) ($entry['units'] ?? []))];
        }
    }

    $rows = [];
    $heldSerials = [];

    foreach ((array) $orders as $order) {
        if (!is_array($order) || !customer_order_record_requires_unit_assignment($order)) {
            continue;
        }

        $statusToken = normalize_customer_order_status_token($order['status'] ?? 'pending');

        foreach (array_values((array) ($order['items'] ?? [])) as $itemIndex => $item) {
            if (!is_array($item)) {
                continue;
            }

            $productKey = customer_order_resolve_item_product_key($item, $products, $nameLookup);

            if ($productKey === '') {
                continue;
            }

            $normalizedKey = normalize_customer_order_product_key($productKey);
            $serials = customer_order_extract_item_assigned_unit_serials($item);

            foreach ($serials as $serial) {
                $heldSerials[$normalizedKey][(int) $serial] = true;
            }

            $rows[] = [
                'orderId' => (string) ($order['id'] ?? ''),
                'itemIndex' => (int) $itemIndex,
                'status' => $statusToken,
                'customerName' => (string) ($order['customer_name'] ?? ''),
                'receive' => trim((string) ($order['receive_date'] ?? '') . ' ' . (string) ($order['receive_time'] ?? '')),
                'return' => trim((string) ($order['return_date'] ?? '') . ' ' . (string) ($order['return_time'] ?? '')),
                'productKey' => $productKey,
                'normalizedKey' => $normalizedKey,
                'qty' => max(1, (int) ($item['qty'] ?? 1)),
                'serials' => $serials,
            ];
        }
    }

    foreach ($rows as $index => $row) {
        $product = isset($products[$row['productKey']]) && is_array($products[$row['productKey']]) ? $products[$row['productKey']] : [];
        $model = function_exists('equipment_model_label_from_product') && $product ? equipment_model_label_from_product($product) : $row['productKey'];
        $units = $inventoryByKey[$row['normalizedKey']]['units'] ?? [];
        $unitBySerial = [];

        foreach ($units as $unit) {
            if (is_array($unit)) {
                $unitBySerial[(int) ($unit['serial'] ?? -1)] = $unit;
            }
        }

        $labelFor = static function ($serial, $unit) use ($model) {
            $fallback = function_exists('equipment_unit_identifier') ? equipment_unit_identifier($model, (int) $serial) : $model . ' #' . (int) $serial;

            return function_exists('equipment_unit_display_label')
                ? equipment_unit_display_label($unit['serialNumber'] ?? '', $fallback)
                : $fallback;
        };

        $assigned = [];
        $usableCount = 0;
        $hasUnusable = false;

        foreach ($row['serials'] as $serial) {
            $unit = $unitBySerial[(int) $serial] ?? null;
            $usable = is_array($unit) && customer_order_unit_counts_for_capacity($unit);
            $unitStatus = is_array($unit) ? customer_order_normalize_equipment_status_token($unit['status'] ?? 'available') : 'missing';

            if ($usable) {
                $usableCount++;
            } else {
                $hasUnusable = true;
            }

            $assigned[] = [
                'serial' => (int) $serial,
                'label' => $labelFor($serial, is_array($unit) ? $unit : []),
                'usable' => $usable,
                'status' => $unitStatus,
                'reason' => is_array($unit) ? (string) ($unit['statusReason'] ?? '') : '',
                'timesUsed' => is_array($unit) ? (int) ($unit['timesUsed'] ?? 0) : 0,
            ];
        }

        $candidates = [];

        foreach ($unitBySerial as $serial => $unit) {
            if (!customer_order_unit_counts_for_capacity($unit) || isset($heldSerials[$row['normalizedKey']][(int) $serial])) {
                continue;
            }

            $candidates[] = [
                'serial' => (int) $serial,
                'label' => $labelFor($serial, $unit),
                'timesUsed' => (int) ($unit['timesUsed'] ?? 0),
            ];
        }

        usort($candidates, static function ($left, $right) {
            $usage = $left['timesUsed'] <=> $right['timesUsed'];

            return $usage !== 0 ? $usage : $left['serial'] <=> $right['serial'];
        });

        $shortfall = max(0, $row['qty'] - $usableCount);

        $rows[$index]['model'] = $model;
        $rows[$index]['assigned'] = $assigned;
        $rows[$index]['shortfall'] = $shortfall;
        $rows[$index]['candidates'] = $candidates;
        $rows[$index]['needsAttention'] = $shortfall > 0 || $hasUnusable;
        $rows[$index]['editable'] = $row['status'] === 'approved';
    }

    usort($rows, static function ($left, $right) {
        $attention = ((int) $right['needsAttention']) <=> ((int) $left['needsAttention']);

        return $attention !== 0 ? $attention : strcmp($left['receive'], $right['receive']);
    });

    return $rows;
}
