<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/orion_pipeline.php';
require_once __DIR__ . '/csv_pipeline.php';
require_once __DIR__ . '/mapper.php';

// Request envelope logging
$method   = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
$action   = $_GET['action'] ?? '(none)';
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
app_log(sprintf("API_REQUEST method=%s action=%s ip=%s", $method, $action, $remoteIp), 'INFO');

// Fixed Orion entity
const ORION_ENTITY_ID   = 'urn.ngsi-Id.TsukubaAnalytics.HomePage.20250201_001';
const ORION_ENTITY_TYPE = 'TsukubaAnalytics';

// Build Fiware headers from config
function get_fiware_headers(): array {
  $cfg = require __DIR__ . '/../../config/config.php';
  $service     = $cfg['fiware_service']     ?? '';
  $servicePath = $cfg['fiware_servicepath'] ?? '/';
  return [
    "Fiware-Service: {$service}",
    "Fiware-ServicePath: {$servicePath}",
    "Accept: application/json"
  ];
}

// Global error-state helpers (backend-centric)
function set_blocking_error_state(string $source, array $errors): void {
  $_SESSION['last_error_state'] = [
    'source'   => $source,
    'blocking' => true,
    'errors'   => $errors
  ];
}

function clear_error_state(): void {
  unset($_SESSION['last_error_state']);
}

// Orion HTTP helpers
function orion_get_entity(): array {
  $cfg = require __DIR__ . '/../../config/config.php';
  $baseUrl = rtrim($cfg['orion_base_url'], '/');
  $url = "{$baseUrl}/v2/entities/" . urlencode(ORION_ENTITY_ID);
  $headers = get_fiware_headers();
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => $cfg['timeout_seconds'] ?? 15
  ]);
  $raw  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ['http_code' => $code, 'error' => $err ?: null, 'response' => $raw];
}

function orion_create_entity(): array {
  $cfg = require __DIR__ . '/../../config/config.php';
  $baseUrl = rtrim($cfg['orion_base_url'], '/');
  $url = "{$baseUrl}/v2/entities";
  $headers = get_fiware_headers();
  $payload = json_encode(['id' => ORION_ENTITY_ID, 'type' => ORION_ENTITY_TYPE], JSON_UNESCAPED_UNICODE);
  $headers[] = "Content-Type: application/json";
  $headers[] = "Content-Length: " . strlen($payload);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => $cfg['timeout_seconds'] ?? 15
  ]);
  $raw  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ['http_code' => $code, 'error' => $err ?: null, 'response' => $raw];
}

function orion_delete_attr(string $attrKey): array {
  $cfg = require __DIR__ . '/../../config/config.php';
  $baseUrl = rtrim($cfg['orion_base_url'], '/');
  $url = "{$baseUrl}/v2/entities/" . urlencode(ORION_ENTITY_ID) . "/attrs/" . urlencode($attrKey);
  $headers = get_fiware_headers();
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'DELETE',
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => $cfg['timeout_seconds'] ?? 15
  ]);
  $raw  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ['http_code' => $code, 'error' => $err ?: null, 'response' => $raw];
}

function orion_post_attrs(array $attrs): array {
  $cfg = require __DIR__ . '/../../config/config.php';
  $baseUrl = rtrim($cfg['orion_base_url'], '/');
  $url = "{$baseUrl}/v2/entities/" . urlencode(ORION_ENTITY_ID) . "/attrs";
  $headers = get_fiware_headers();
  $payload = json_encode($attrs, JSON_UNESCAPED_UNICODE);
  $headers[] = "Content-Type: application/json";
  $headers[] = "Content-Length: " . strlen($payload);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => $cfg['timeout_seconds'] ?? 15
  ]);
  $raw  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ['http_code' => $code, 'error' => $err ?: null, 'response' => $raw];
}

// Type coercion enforcement for NGSIv2 posts
function coerce_typed_value(string $attrType, $attrValue) {
  $t = strtolower(trim($attrType));
  if ($t === 'integer') {
    if (!preg_match('/^\-?\d+$/', (string)$attrValue)) return [false, "Invalid integer"];
    return [true, (int)$attrValue];
  }
  if ($t === 'float') {
    if (!preg_match('/^\-?\d+\.\d+$/', (string)$attrValue)) return [false, "Invalid float"];
    return [true, (float)$attrValue];
  }
  if ($t === 'datetime') {
    if (!preg_match('/^\d{4}\-\d{2}\-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(Z|[\+\-]\d{2}:\d{2})$/', (string)$attrValue)) {
      return [false, "Invalid DateTime (ISO8601 with timezone required)"];
    }
    return [true, (string)$attrValue];
  }
  return [true, (string)$attrValue];
}

// Flatten Format-B entities to lowercased key map
function flatten_format_b_to_map(array $entities): array {
  $out = [];
  foreach (($entities ?? []) as $e) {
    $attrs = $e['attributes'] ?? [];
    foreach ($attrs as $k => $attr) {
      $type  = is_array($attr) ? ($attr['type']  ?? null) : null;
      $value = is_array($attr) ? ($attr['value'] ?? null) : $attr;
      $out[strtolower($k)] = ['type' => $type, 'value' => $value];
    }
  }
  return $out;
}

// Compare typed attributes across Orion and CSV previews stored in session
function compare_types_orion_vs_csv_from_session(): array {
  $orion = $_SESSION['latest_orion_json'] ?? null;
  $csv   = $_SESSION['latest_csv_json']   ?? null;
  $mismatchKeys = [];
  if (!is_array($orion) || !is_array($csv)) {
    return ['has_mismatch' => false, 'keys' => []];
  }
  $oMap = flatten_format_b_to_map($orion);
  $cMap = flatten_format_b_to_map($csv);
  $allKeys = array_unique(array_merge(array_keys($oMap), array_keys($cMap)));
  foreach ($allKeys as $kl) {
    $oType = $oMap[$kl]['type'] ?? null;
    $cType = $cMap[$kl]['type'] ?? null;
    if ($oType !== null && $cType !== null && strval($oType) !== strval($cType)) {
      $mismatchKeys[] = $kl;
    }
  }
  return ['has_mismatch' => count($mismatchKeys) > 0, 'keys' => $mismatchKeys];
}

// ACTION: fetch_orion
if (isset($_GET['action']) && $_GET['action'] === 'fetch_orion') {
  $service     = $_GET['service']     ?? null;
  $servicePath = $_GET['servicepath'] ?? null;
  $opt = [];
  if ($service)     $opt['service']     = $service;
  if ($servicePath) $opt['servicePath'] = $servicePath;

  app_log("ACTION fetch_orion START", "INFO");
  $res = run_orion_pipeline($opt);

  if (($res['status'] ?? '') === 'success') {
    $entities = $res['preview'] ?? [];
    $attrCount = 0;
    foreach (($entities ?? []) as $e) { $attrCount += count($e['attributes'] ?? []); }
    app_log(sprintf("ACTION fetch_orion SUCCESS entities=%d attributes=%d", count($entities ?? []), $attrCount), 'INFO');

    clear_error_state();

    echo json_encode([
      'status'   => 'success',
      'preview'  => $res['preview'] ?? [],
      'blocking' => false,
      'can_push' => true
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
  } else {
    $msg = $res['message'] ?? 'unknown';
    app_log("ACTION fetch_orion ERROR: " . $msg, 'ERROR');
    set_blocking_error_state('orion', [$msg]);

    echo json_encode([
      'status'     => 'error',
      'error_code' => 'ORION_FETCH_FAILED',
      'message'    => $msg,
      'errors'     => [$msg],
      'blocking'   => true,
      'can_push'   => false
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
  }
}

// ACTION: upload_csv
if (isset($_GET['action']) && $_GET['action'] === 'upload_csv') {
  if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    $msg = 'CSV upload failed';
    app_log("ACTION upload_csv ERROR: " . $msg, 'ERROR');
    set_blocking_error_state('csv', [$msg]);
    echo json_encode([
      'status'     => 'error',
      'error_code' => 'CSV_UPLOAD_FAILED',
      'message'    => $msg,
      'errors'     => [$msg],
      'blocking'   => true,
      'can_push'   => false
    ]);
    exit;
  }

  $filepath = $_FILES['csv_file']['tmp_name'];
  app_log("ACTION upload_csv START", 'INFO');
  $result = run_csv_pipeline_debug($filepath);

  if (($result['status'] ?? '') !== 'success') {
    $errors  = $result['errors'] ?? [$result['message'] ?? 'unknown'];
    $preview = $result['preview'] ?? []; // preview provided on non-fatal errors
    set_blocking_error_state('csv', $errors);
    app_log("ACTION upload_csv ERROR: " . ($result['message'] ?? 'unknown'), 'ERROR');

    echo json_encode([
      'status'     => 'error',
      'error_code' => 'CSV_VALIDATION',
      'message'    => $result['message'] ?? 'Validation failed',
      'errors'     => $errors,
      'preview'    => $preview,
      'blocking'   => true,
      'can_push'   => false
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  
  // SUCCESS (may have non-fatal errors that must block push_json)
  $preview = $result['preview'] ?? [];
  $previewRows = $result['preview_rows'] ?? [];
  $uiErrorsResp = $result['ui_errors'] ?? [];
  $skipped = $result['skipped_jp_names'] ?? [];

  $attrCount = 0;
  foreach (($preview ?? []) as $e) { $attrCount += count($e['attributes'] ?? []); }
  $skipped = $result['skipped_jp_names'] ?? [];
  if (!empty($skipped)) {
    app_log("CSV_MAPPING_SKIP: count=" . count($skipped) . " items=" . implode(', ', $skipped), 'INFO');
  }
  app_log(sprintf("ACTION upload_csv SUCCESS entities=%d attributes=%d", count($preview ?? []), $attrCount), 'INFO');

  // Decide blocking based on non-fatal errors
  $hasNonFatalErrors = 
  !empty($uiErrorsResp['symbols']) ||
  !empty($uiErrorsResp['missing_name']) ||
  !empty($uiErrorsResp['type_mismatch']) ||
  !empty($uiErrorsResp['skipped']) ||
  !empty($uiErrorsResp['multiline']);

  if ($hasNonFatalErrors) {
    // Block push_json
    set_blocking_error_state('csv_nonfatal', [
      'symbols_count'=> count($uiErrorsResp['symbols'] ?? []),
      'missing_name_count'=> count($uiErrorsResp['missing_name'] ?? []),
      'type_mismatch_count'=> count($uiErrorsResp['type_mismatch'] ?? []),
      'skipped_count'=> count($uiErrorsResp['skipped'] ?? [])
    ]);
  } else {
    clear_error_state();
  }

  echo json_encode([
    'status'=> 'success',
    'preview'=> $preview,
    'preview_rows'=> $previewRows,
    'ui_errors'=> $uiErrorsResp,
    'blocking'=> $hasNonFatalErrors,
    'can_push'=> $hasNonFatalErrors ? false : true
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// ACTION: push_json
if (isset($_GET['action']) && $_GET['action'] === 'push_json') {
  $targetUrl = $_POST['url'] ?? '';
  if (!$targetUrl) {
    echo json_encode(['status' => 'error', 'error_code' => 'MISSING_URL', 'message' => 'Missing url'], JSON_PRETTY_PRINT);
    exit;
  }

  // Block for non fatal errors
  if (!empty($_SESSION['latest_csv_has_errors'])) {
    $uiErr = $_SESSION['latest_csv_ui_errors'] ?? [];
    app_log("ACTION push_json BLOCKED due to CSV UI errors (non-fatal): 
      symbols=" . count($uiErr['symbols'] ?? []) . 
      "missing_name=" . count($uiErr['missing_name'] ?? []) . 
      "type_mismatch=" . count($uiErr['type_mismatch'] ?? []) .
      "skipped=" . count($uiErr['skipped'] ?? []), 'ERROR');

    echo json_encode([
      'status'=> 'error',
      'error_code'=> 'BLOCKED_BY_CSV_ERRORS',
      'message'=> '未解決のエラーがあるため、JSONの送信はできません。',
      'ui_errors' => $uiErr
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
  }

  // Block by global errors
  if (isset($_SESSION['last_error_state']) && ($_SESSION['last_error_state']['blocking'] ?? false)) {
    $errs = $_SESSION['last_error_state']['errors'] ?? [];
    app_log("ACTION push_json BLOCKED due to ERRORS: " . implode(' | ', $errs), 'ERROR');
    echo json_encode([
      'status'     => 'error',
      'error_code' => 'BLOCKED_BY_ERRORS',
      'message'    => 'エラーが解消されるまで「登録」はできません。',
      'errors'     => $errs
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
  }

  // Block by typed mismatch between Orion & CSV sources
  $cmp = compare_types_orion_vs_csv_from_session();
  if ($cmp['has_mismatch']) {
    app_log("ACTION push_json BLOCKED due to TYPE_MISMATCH keys=" . implode(',', $cmp['keys']), 'ERROR');
    echo json_encode([
      'status'      => 'error',
      'error_code'  => 'TYPE_MISMATCH',
      'message'     => 'OrionとCSVのデータ型が一致しません。型を合わせてから登録してください。',
      'mismatchKeys'=> $cmp['keys']
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
  }

  // Determine source (CSV has precedence if present)
  $jsonData = null;
  $source   = '';
  if (isset($_SESSION['latest_csv_json'])) {
    $jsonData = $_SESSION['latest_csv_json'];
    $source   = 'CSV';
  } elseif (isset($_SESSION['latest_orion_json'])) {
    $jsonData = $_SESSION['latest_orion_json'];
    $source   = 'Orion';
  }
  if ($jsonData === null) {
    echo json_encode(['status' => 'error', 'error_code' => 'NO_DATA', 'message' => 'No JSON data stored in session (CSV and Orion)'], JSON_PRETTY_PRINT);
    exit;
  }

  // Push to external app
  $payload = json_encode($jsonData, JSON_UNESCAPED_UNICODE);
  app_log("ACTION push_json START url=" . $targetUrl, "INFO");
  $ch = curl_init($targetUrl);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Content-Length: " . strlen($payload)]);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  $response = curl_exec($ch);
  $err      = curl_error($ch);
  $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  app_log(sprintf("ACTION push_json RESULT http_code=%d error=%s", $code, $err ? $err : 'none'), $err ? 'ERROR' : 'INFO');

  if ($err) {
    set_blocking_error_state('push', [$err]);
    echo json_encode(['status' => 'error', 'error_code' => 'PUSH_FAILED', 'error' => $err], JSON_PRETTY_PRINT);
    exit;
  }

  echo json_encode([
    'status'    => 'success',
    'source'    => $source,
    'http_code' => $code,
    'response'  => $response
  ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

// ACTION: get_mapping
if (isset($_GET['action']) && $_GET['action'] === 'get_mapping') {
  header("Content-Type: application/json; charset=utf-8");
  app_log("ACTION get_mapping START", "INFO");
  $map = load_attribute_map();
  if (empty($map)) {
    $msg = 'Mapping file missing or unreadable';
    app_log("ACTION get_mapping ERROR: " . $msg, 'ERROR');
    set_blocking_error_state('mapping', [$msg]);
    echo json_encode([
      'status'     => 'error',
      'error_code' => 'MAPPING_NOT_FOUND',
      'message'    => $msg,
      'blocking'   => true,
      'can_push'   => false
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
  }
  app_log("ACTION get_mapping SUCCESS count=" . count($map), "INFO");
  echo json_encode(['status' => 'success', 'map' => $map], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  exit;
}

// ACTION: orion_delete_attr
if (isset($_GET['action']) && $_GET['action'] === 'orion_delete_attr') {
  $attrKey = $_POST['attrKey'] ?? '';
  if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $attrKey)) {
    $msg = 'Invalid attrKey';
    set_blocking_error_state('delete', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'INVALID_ATTRKEY', 'message' => $msg, 'blocking' => true, 'can_push' => false]);
    exit;
  }
  app_log("ACTION orion_delete_attr START key=" . $attrKey, "INFO");
  $orion = orion_delete_attr($attrKey);
  if ($orion['error']) {
    $msg = 'Orion DELETE failed';
    set_blocking_error_state('delete', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'ORION_DELETE_FAILED', 'message' => $msg, 'detail' => $orion, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($orion['http_code'] >= 500) {
    $msg = 'Orion DELETE server error';
    set_blocking_error_state('delete', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'ORION_DELETE_SERVER_ERROR', 'message' => $msg, 'detail' => $orion, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $removed = remove_mapping_by_key($attrKey);

  clear_error_state();

  echo json_encode([
    'status'          => 'success',
    'http_code'       => $orion['http_code'],
    'deleted_key'     => $attrKey,
    'removed_mapping' => $removed,
    'blocking'        => false,
    'can_push'        => true
  ], JSON_UNESCAPED_UNICODE);
  app_log(sprintf("ACTION orion_delete_attr RESULT http_code=%d removed_mapping=%s", $orion['http_code'], $removed ? 'true' : 'false'), 'INFO');
  exit;
}

// ACTION: orion_add_attr
if (isset($_GET['action']) && $_GET['action'] === 'orion_add_attr') {
  $jpName    = trim($_POST['jpName']    ?? '');
  $attrKey   = trim($_POST['attrKey']   ?? '');
  $attrType  = trim($_POST['attrType']  ?? '');
  $attrValue = $_POST['attrValue']      ?? '';

  // Basic validations
  if ($jpName === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $attrKey)) {
    $msg = 'Invalid jpName or attrKey';
    set_blocking_error_state('add', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'INVALID_INPUT', 'message' => $msg, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $validTypes = ['Text', 'Integer', 'Float', 'DateTime'];
  if (!in_array($attrType, $validTypes, true)) {
    $msg = 'Invalid attrType';
    set_blocking_error_state('add', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'INVALID_TYPE', 'message' => $msg, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Duplicate checks using mapping
  $map = load_attribute_map();

  // Duplicate jpName (including whitespace normalization)
  $jpExists = false;
  if (isset($map[$jpName])) { $jpExists = true; } else {
    $normalizedInput = preg_replace('/\s+/', '', $jpName);
    foreach ($map as $existingJp => $info) {
      $normalizedExisting = preg_replace('/\s+/', '', $existingJp);
      if ($normalizedExisting === $normalizedInput) { $jpExists = true; break; }
    }
  }
  if ($jpExists) {
    $msg = "同じ項目名がすでに登録されています。\n別名に変更するか、既存項目を削除してください。";
    set_blocking_error_state('add', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'DUPLICATE_JPNAME', 'message' => $msg, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Duplicate system key (case-insensitive)
  $existingJpForKey = find_jp_name_for_key($attrKey);
  if ($existingJpForKey !== null) {
    $msg = "同じシステム項目名がすでに登録されています（既存の項目名：{$existingJpForKey}）。\n別のシステム項目名を使用するか、既存項目を削除してください。";
    set_blocking_error_state('add', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'DUPLICATE_KEY', 'message' => $msg, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }

  app_log(sprintf("ACTION orion_add_attr START key=%s type=%s", $attrKey, $attrType), 'INFO');

  // Ensure entity exists
  $probe = orion_get_entity();
  if ($probe['error']) {
    $msg = 'Orion GET failed';
    set_blocking_error_state('add', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'ORION_GET_FAILED', 'message' => $msg, 'detail' => $probe, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($probe['http_code'] === 404) {
    $create = orion_create_entity();
    if ($create['error'] || ($create['http_code'] < 200 || $create['http_code'] >= 300)) {
      $msg = 'Entity create failed';
      set_blocking_error_state('add', [$msg]);
      echo json_encode(['status' => 'error', 'error_code' => 'ORION_CREATE_FAILED', 'message' => $msg, 'detail' => $create, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } elseif ($probe['http_code'] >= 500) {
    $msg = 'Orion GET server error';
    set_blocking_error_state('add', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'ORION_GET_SERVER_ERROR', 'message' => $msg, 'detail' => $probe, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Coerce value by type
  [$ok, $coerced] = coerce_typed_value($attrType, $attrValue);
  if (!$ok) {
    $msg = "Value/type mismatch: {$coerced}";
    set_blocking_error_state('add', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'TYPE_VALUE_MISMATCH', 'message' => $msg, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $typedAttr = [
    $attrKey => ['type' => $attrType, 'value' => $coerced]
  ];

  // POST to Orion
  $post = orion_post_attrs($typedAttr);
  if ($post['error']) {
    $msg = 'Orion POST failed';
    set_blocking_error_state('add', [$msg]);
    echo json_encode(['status' => 'error', 'error_code' => 'ORION_POST_FAILED', 'message' => $msg, 'detail' => $post, 'blocking' => true, 'can_push' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($post['http_code'] >= 200 && $post['http_code'] < 300) {
    $added = add_mapping_entry($jpName, $attrKey, $attrType);

    clear_error_state();

    echo json_encode([
      'status'   => 'success',
      'http_code'=> $post['http_code'],
      'added'    => ['jpName' => $jpName, 'key' => $attrKey, 'type' => $attrType],
      'mapping'  => $added ? 'updated' : 'not-updated',
      'blocking' => false,
      'can_push' => true
    ], JSON_UNESCAPED_UNICODE);
    app_log(sprintf("ACTION orion_add_attr SUCCESS http_code=%d mapping=%s", $post['http_code'], $added ? 'updated' : 'not-updated'), 'INFO');
    exit;
  }

  $msg = 'Orion POST non-2xx (attribute may already exist or type conflict). Use a different Key or delete the attribute first.';
  set_blocking_error_state('add', [$msg]);
  echo json_encode([
    'status'     => 'error',
    'error_code' => 'ORION_POST_NON_2XX',
    'message'    => $msg,
    'detail'     => $post,
    'blocking'   => true,
    'can_push'   => false
  ], JSON_UNESCAPED_UNICODE);
  app_log(sprintf("ACTION orion_add_attr ERROR http_code=%d error=%s", $post['http_code'] ?? 0, $post['error'] ?? 'none'), 'ERROR');
  exit;
}

// Default invalid action
echo json_encode(['status' => 'error', 'error_code' => 'INVALID_ACTION', 'message' => 'Invalid action'], JSON_PRETTY_PRINT);
exit;
