
<?php
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/mapper.php';
require_once __DIR__ . '/normalizer.php';

// Fetch Orion entities for given service/servicePath with simple retry
function fetch_from_orion_service(string $service, string $servicePath = '/', int $timeout = 15, int $retries = 1, int $retry_sleep_ms = 200) {
  $cfg  = require __DIR__ . '/../../config/config.php';
  $base = rtrim($cfg['orion_base_url'], '/');
  $url  = $base . "/v2/entities";
  $headers = [
    "Fiware-Service: $service",
    "Fiware-ServicePath: $servicePath",
    "Accept: application/json"
  ];
  $attempt = 0;
  $lastErr = null;
  while ($attempt <= $retries) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_HTTPHEADER     => $headers
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
      $lastErr = $err;
    } elseif ($code >= 200 && $code < 300) {
      $json = json_decode($raw, true);
      if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        $lastErr = "Invalid JSON returned from Orion";
      } else {
        return ['success' => true, 'entities' => $json, 'raw_response' => $raw];
      }
    } else {
      $lastErr = "HTTP $code";
    }

    if ($attempt < $retries) {
      usleep($retry_sleep_ms * 1000);
    }
    $attempt++;
  }
  return ['success' => false, 'error' => $lastErr];
}

// Synchronize mapping file with Orion preview attributes
function sync_mapping_from_orion_preview(array $previewRows): array {
  $orionKeysLower  = [];
  $orionTypesByKey = [];
  foreach (($previewRows ?? []) as $entity) {
    $attrs = $entity['attributes'] ?? [];
    foreach ($attrs as $attrKey => $attrData) {
      $lower = strtolower($attrKey);
      $orionKeysLower[$lower] = true;
      $orionTypesByKey[$attrKey] = is_array($attrData) ? ($attrData['type'] ?? 'Text') : 'Text';
    }
  }

  $map     = load_attribute_map();
  $reverse = []; // lower[key] => jpName
  foreach ($map as $jpName => $info) {
    $k = isset($info['key']) ? strtolower($info['key']) : null;
    if ($k) $reverse[$k] = $jpName;
  }

  $added   = [];
  $removed = [];

  // Add missing keys found in Orion
  foreach ($orionTypesByKey as $key => $type) {
    $lower = strtolower($key);
    if (!isset($reverse[$lower])) {
      $ok = add_mapping_entry($key, $key, $type ?: 'Text');
      if ($ok) {
        $added[]        = ['jpName' => $key, 'key' => $key, 'type' => $type ?: 'Text'];
        $reverse[$lower]= $key;
      }
    }
  }

  // Remove keys not present in Orion
  foreach ($map as $jpName => $info) {
    $mappedKey = $info['key'] ?? null;
    if (!$mappedKey) continue;
    if (!isset($orionKeysLower[strtolower($mappedKey)])) {
      $ok = remove_mapping_by_key($mappedKey);
      if ($ok) {
        $removed[] = ['jpName' => $jpName, 'key' => $mappedKey];
      }
    }
  }

  return ['added' => $added, 'removed' => $removed];
}

// Run entire Orion pipeline: fetch, normalize, build preview, session-store, sync mapping
function run_orion_pipeline(array $opts = []) {
  $cfg         = require __DIR__ . '/../../config/config.php';
  $service     = $opts['service']     ?? $cfg['fiware_service'];
  $servicePath = $opts['servicePath'] ?? $cfg['fiware_servicepath'];
  $timeout     = $cfg['timeout_seconds'] ?? 15;
  $retries     = $cfg['http_retries']    ?? 1;
  $sleep_ms    = $cfg['retry_sleep_ms']  ?? 200;

  $fetch = fetch_from_orion_service($service, $servicePath, $timeout, $retries, $sleep_ms);
  if (!$fetch['success']) {
    $msg = "FETCH_ORION failed: " . ($fetch['error'] ?? 'unknown');
    app_log($msg, 'ERROR');
    return ['status' => 'error', 'message' => $msg];
  }

  $entities   = $fetch['entities'];
  $normalized = normalize_ngsi_entities($entities);
  $preview    = build_preview_rows($normalized);

  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $_SESSION['latest_orion_json'] = $preview;

  $syncResult = sync_mapping_from_orion_preview($preview);
  app_log(sprintf("Mapping_Sync: added=%d, removed=%d", count($syncResult['added'] ?? []), count($syncResult['removed'] ?? [])), 'INFO');

  $totalEntities = count($normalized);
  $totalAttrs    = 0;
  foreach ($normalized as $e) { $totalAttrs += count($e['attributes'] ?? []); }
  app_log(sprintf("FETCH_ORION: Service=%s ServicePath=%s Entities=%d Attributes=%d", $service, $servicePath, $totalEntities, $totalAttrs), 'INFO');

   return ['status' => 'success', 'preview' => $preview];
}