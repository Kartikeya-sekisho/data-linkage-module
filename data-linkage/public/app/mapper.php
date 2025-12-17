
<?php
// public/app/mapper.php

/**
 * Load attribute mapping.
 * Expected format (example):
 * {
 *   "つくば市_人口": {
 *     "key": "tsukubaCityPopulation",
 *     "type": "Integer"
 *     // optional extra fields are ignored by the pipeline
 *   },
 *   "市域全体_出生率": {
 *     "key": "cityBirthRate",
 *     "type": "Float"
 *   }
 * }
 */
function load_attribute_map(): array {
    $cfg     = require __DIR__ . '/../../config/config.php';
    $mapPath = rtrim($cfg['mapping_dir'], '/\\') . '/attribute_key_map.json';

    if (!file_exists($mapPath)) {
        error_log("[MAPPER_ERROR] Mapping file not found at: $mapPath");
        return [];
    }
    $contents = file_get_contents($mapPath);
    if ($contents === false) {
        error_log("[MAPPER_ERROR] Cannot read mapping file: $mapPath");
        return [];
    }
    $raw = json_decode($contents, true);
    if (!is_array($raw)) {
        error_log("[MAPPER_ERROR] Invalid JSON in mapping file: $mapPath. Error: " . json_last_error_msg());
        return [];
    }
    return $raw;
}

// Save attribute mapping safely (LOCK_EX)
function save_attribute_map(array $map): bool {
    $cfg     = require __DIR__ . '/../../config/config.php';
    $mapPath = rtrim($cfg['mapping_dir'], '/\\') . '/attribute_key_map.json';

    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        error_log("[MAPPER_ERROR] Failed to encode mapping JSON: " . json_last_error_msg());
        return false;
    }
    return file_put_contents($mapPath, $json, LOCK_EX) !== false;
}

// Find mapping info for a Japanese display name 
function find_mapping_for_jp_name(string $jpName): ?array {
    $map = load_attribute_map();
    if (empty($map)) return null;

    // 1) direct match
    if (isset($map[$jpName])) {
        return $map[$jpName];
    }

    // 2) trimmed match
    $trimmed = trim($jpName);
    if ($trimmed !== $jpName && isset($map[$trimmed])) {
        return $map[$trimmed];
    }

    // 3) normalized match: remove all whitespace
    $normalized = preg_replace('/\s+/', '', $jpName);
    foreach ($map as $mapKey => $mapValue) {
        $mapNormalized = preg_replace('/\s+/', '', $mapKey);
        if ($mapNormalized === $normalized) {
            return $mapValue;
        }
    }

    return null;
}

/**
 * Reverse lookup: English key -> Japanese name
 */
function find_jp_name_for_key(string $engKey): ?string {
    $map = load_attribute_map();
    if (empty($map)) return null;

    $keyLower = strtolower($engKey);
    foreach ($map as $jpName => $info) {
        if (isset($info['key']) && strtolower($info['key']) === $keyLower) {
            return $jpName;
        }
    }
    return null;
}

/**
 * Remove mapping entry by English key (returns true if the map was updated).
 */
function remove_mapping_by_key(string $key): bool {
    $map = load_attribute_map();
    if (empty($map)) return false;

    $jpName = find_jp_name_for_key($key);
    if ($jpName === null) return false;

    unset($map[$jpName]);
    return save_attribute_map($map);
}

/**
 * Add/overwrite a mapping entry (returns true if the map was updated).
 */
function add_mapping_entry(string $jpName, string $key, string $type): bool {
    $map = load_attribute_map();
    $map[$jpName] = [
        'key'  => $key,
        'type' => $type
    ];
    return save_attribute_map($map);
}

/**
 * Build preview rows for Orion data (input is already Format B).
 * Return as-is.
 */
function build_preview_rows(array $normalizedEntities): array {
    return $normalizedEntities;
}