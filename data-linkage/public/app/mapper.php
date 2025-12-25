<?php
// Load attribute mapping file (JSON object of jpName -> {key, type})
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

// Save mapping atomically
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

// Find mapping for a Japanese display name (direct, trimmed, whitespace-normalized)
function find_mapping_for_jp_name(string $jpName): ?array {
  $map = load_attribute_map();
  if (empty($map)) return null;
  if (isset($map[$jpName])) return $map[$jpName];
  $trimmed = trim($jpName);
  if ($trimmed !== $jpName && isset($map[$trimmed])) return $map[$trimmed];
  $normalized = preg_replace('/\s+/', '', $jpName);
  foreach ($map as $mapKey => $mapValue) {
    $mapNormalized = preg_replace('/\s+/', '', $mapKey);
    if ($mapNormalized === $normalized) return $mapValue;
  }
  return null;
}

// Reverse lookup: English key -> Japanese name (case-insensitive key comparison)
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

// Remove mapping entry by English key
function remove_mapping_by_key(string $key): bool {
  $map = load_attribute_map();
  if (empty($map)) return false;
  $jpName = find_jp_name_for_key($key);
  if ($jpName === null) return false;
  unset($map[$jpName]);
  return save_attribute_map($map);
}

// Add or overwrite mapping entry
function add_mapping_entry(string $jpName, string $key, string $type): bool {
  $map = load_attribute_map();
  $map[$jpName] = ['key' => $key, 'type' => $type];
  return save_attribute_map($map);
}

// Build preview rows for Orion data (input is already Format-B)
function build_preview_rows(array $normalizedEntities): array {
  return $normalizedEntities;
}
