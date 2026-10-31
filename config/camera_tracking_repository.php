<?php

/**
 * Camera tracking record: who used which serial number, and damage incident reports.
 * Stored as JSON next to the other repositories:
 *   config/camera_usage_log.json     one row per (order, unit) when a rental is completed
 *   config/equipment_incidents.json  incident reports filed when a returned unit is marked broken
 */

function camera_tracking_usage_log_path()
{
    return __DIR__ . '/camera_usage_log.json';
}

function camera_tracking_incidents_path()
{
    return __DIR__ . '/equipment_incidents.json';
}

function camera_tracking_read_json($path)
{
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

function camera_tracking_write_json($path, $rows)
{
    $encoded = json_encode(array_values((array) $rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        return false;
    }

    return file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) !== false;
}

function load_camera_usage_log_repository()
{
    return camera_tracking_read_json(camera_tracking_usage_log_path());
}

function save_camera_usage_log_repository($log)
{
    return camera_tracking_write_json(camera_tracking_usage_log_path(), $log);
}

function load_equipment_incidents_repository()
{
    return camera_tracking_read_json(camera_tracking_incidents_path());
}

function save_equipment_incidents_repository($incidents)
{
    return camera_tracking_write_json(camera_tracking_incidents_path(), $incidents);
}

/**
 * Adds usage rows (skips rows whose id already exists, so completing twice never duplicates).
 */
function camera_tracking_append_usage_entries($entries)
{
    if (!is_array($entries) || $entries === []) {
        return true;
    }

    $log = load_camera_usage_log_repository();
    $known = [];

    foreach ($log as $row) {
        if (is_array($row) && isset($row['id'])) {
            $known[(string) $row['id']] = true;
        }
    }

    foreach ($entries as $entry) {
        if (!is_array($entry) || isset($known[(string) ($entry['id'] ?? '')])) {
            continue;
        }

        $log[] = $entry;
    }

    return save_camera_usage_log_repository($log);
}

function camera_tracking_entry_matches_unit($entry, $productKey, $serial)
{
    return is_array($entry)
        && (string) ($entry['productKey'] ?? '') === (string) $productKey
        && (int) ($entry['serial'] ?? -1) === (int) $serial;
}

/**
 * Most recent usage row for one unit (by returnedAt), or null.
 */
function camera_tracking_last_usage_for_unit($log, $productKey, $serial)
{
    $latest = null;

    foreach ((array) $log as $entry) {
        if (!camera_tracking_entry_matches_unit($entry, $productKey, $serial)) {
            continue;
        }

        if ($latest === null || strcmp((string) ($entry['returnedAt'] ?? ''), (string) ($latest['returnedAt'] ?? '')) > 0) {
            $latest = $entry;
        }
    }

    return $latest;
}

/**
 * Records the inspection result on the most recent usage row of the unit.
 */
function camera_tracking_set_inspection($productKey, $serial, $result, $inspectedBy)
{
    $log = load_camera_usage_log_repository();
    $targetIndex = null;

    foreach ($log as $index => $entry) {
        if (!camera_tracking_entry_matches_unit($entry, $productKey, $serial)) {
            continue;
        }

        if ($targetIndex === null || strcmp((string) ($entry['returnedAt'] ?? ''), (string) ($log[$targetIndex]['returnedAt'] ?? '')) > 0) {
            $targetIndex = $index;
        }
    }

    if ($targetIndex === null) {
        return false;
    }

    $log[$targetIndex]['inspection'] = (string) $result;
    $log[$targetIndex]['inspectedAt'] = gmdate('c');
    $log[$targetIndex]['inspectedBy'] = (string) $inspectedBy;

    return save_camera_usage_log_repository($log);
}

function camera_tracking_create_incident($incident)
{
    $incidents = load_equipment_incidents_repository();

    $incident['id'] = 'inc-' . date('Ymd-His') . '-' . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $incident['createdAt'] = gmdate('c');
    $incident['status'] = 'open';
    $incident['resolvedAt'] = '';
    $incident['resolvedBy'] = '';

    $incidents[] = $incident;

    return save_equipment_incidents_repository($incidents) ? $incident : null;
}

function camera_tracking_resolve_incident($incidentId, $resolvedBy)
{
    $incidents = load_equipment_incidents_repository();
    $matched = false;

    foreach ($incidents as $index => $incident) {
        if (!is_array($incident) || (string) ($incident['id'] ?? '') !== (string) $incidentId) {
            continue;
        }

        if (($incident['status'] ?? 'open') === 'resolved') {
            return false;
        }

        $incidents[$index]['status'] = 'resolved';
        $incidents[$index]['resolvedAt'] = gmdate('c');
        $incidents[$index]['resolvedBy'] = (string) $resolvedBy;
        $matched = true;
        break;
    }

    return $matched && save_equipment_incidents_repository($incidents);
}
