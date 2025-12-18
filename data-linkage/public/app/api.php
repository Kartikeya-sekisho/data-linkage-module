<?php
session_start(); // for session storage
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/orion_pipeline.php';
require_once __DIR__ . '/csv_pipeline.php';
require_once __DIR__ . '/mapper.php';

// Request envelope log
$method   = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
$action   = $_GET['action'] ?? '(none)';
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
app_log(sprintf("API_REQUEST method=%s action=%s ip=%s", $method, $action, $remoteIp), 'INFO');

const ORION_ENTITY_ID   = 'urn.ngsi-Id.TsukubaAnalytics.HomePage.20250201_001';
const ORION_ENTITY_TYPE = 'TsukubaAnalytics';

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

function orion_get_entity(): array {
    $cfg     = require __DIR__ . '/../../config/config.php';
    $baseUrl = rtrim($cfg['orion_base_url'], '/');
    $url     = "{$baseUrl}/v2/entities/" . urlencode(ORION_ENTITY_ID);

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
    $cfg     = require __DIR__ . '/../../config/config.php';
    $baseUrl = rtrim($cfg['orion_base_url'], '/');
    $url     = "{$baseUrl}/v2/entities";

    $headers = get_fiware_headers();
    $payload = json_encode([
        'id'   => ORION_ENTITY_ID,
        'type' => ORION_ENTITY_TYPE
    ], JSON_UNESCAPED_UNICODE);

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
    $cfg     = require __DIR__ . '/../../config/config.php';
    $baseUrl = rtrim($cfg['orion_base_url'], '/');
    $url     = "{$baseUrl}/v2/entities/" . urlencode(ORION_ENTITY_ID) . "/attrs/" . urlencode($attrKey);

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
    $cfg     = require __DIR__ . '/../../config/config.php';
    $baseUrl = rtrim($cfg['orion_base_url'], '/');
    $url     = "{$baseUrl}/v2/entities/" . urlencode(ORION_ENTITY_ID) . "/attrs";

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

// Typed value conversion for NGSIv2
function coerce_typed_value(string $attrType, $attrValue) {
    $t = strtolower(trim($attrType));
    if ($t === 'integer') {
        if (!preg_match('/^-?\d+$/', (string)$attrValue)) return [false, "Invalid integer"];
        return [true, (int)$attrValue];
    }
    if ($t === 'float') {
        if (!preg_match('/^-?\d+\.\d+$/', (string)$attrValue)) return [false, "Invalid float"];
        return [true, (float)$attrValue];
    }
    if ($t === 'datetime') {
        // Basic ISO 8601 validation
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(Z|[+\-]\d{2}:\d{2})?$/', (string)$attrValue)) {
            return [false, "Invalid DateTime (ISO8601 required)"];
        }
        return [true, (string)$attrValue];
    }
    // Text fallback
    return [true, (string)$attrValue];
}

// Flatten Format-B entities to map: keyLower => ['type' => ..., 'value' => ...]
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

/**
 * Compare types across Orion & CSV format-B arrays (from session).
 * Returns ['has_mismatch' => bool, 'keys' => [...]].
 */
function compare_types_orion_vs_csv_from_session(): array {
    $orion = $_SESSION['latest_orion_json'] ?? null;
    $csv   = $_SESSION['latest_csv_json']   ?? null;
    $mismatchKeys = [];

    if (!is_array($orion) || !is_array($csv)) {
        return ['has_mismatch' => false, 'keys' => []]; // nothing to compare
    }

    $oMap = flatten_format_b_to_map($orion);
    $cMap = flatten_format_b_to_map($csv);

    $allKeys = array_unique(array_merge(array_keys($oMap), array_keys($cMap)));
    foreach ($allKeys as $kl) {
        $oType = $oMap[$kl]['type'] ?? null;
        $cType = $cMap[$kl]['type'] ?? null;

        // Only consider mismatch when both sides have a type
        if ($oType !== null && $cType !== null && strval($oType) !== strval($cType)) {
            $mismatchKeys[] = $kl;
        }
    }
    return ['has_mismatch' => count($mismatchKeys) > 0, 'keys' => $mismatchKeys];
}

// ACTION 1 - fetch_orion
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
    } else {
        app_log("ACTION fetch_orion ERROR: " . ($res['message'] ?? 'unknown'), 'ERROR');
    }

    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ACTION 2 - upload_csv
if (isset($_GET['action']) && $_GET['action'] === 'upload_csv') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'CSV upload failed']);
        exit;
    }
    $filepath = $_FILES['csv_file']['tmp_name'];

    app_log("ACTION upload_csv START", 'INFO');

    $result   = run_csv_pipeline_debug($filepath);

    if (($result['status'] ?? '') === 'success') {
        $preview = $result['preview'] ?? [];
        $attrCount = 0;
        foreach (($preview ?? []) as $e) { $attrCount += count($e['attributes'] ?? []); }
        app_log(sprintf("ACTION upload_csv SUCCESS entities=%d attributes=%d", count($preview ?? []), $attrCount), 'INFO');
    } else {
        app_log("ACTION upload_csv ERROR: " . ($result['message'] ?? 'unknown'), 'ERROR');
    }

    if ($result['status'] !== 'success') {
        echo json_encode(['status' => 'error', 'message' => $result['message']]);
        exit;
    }
    echo json_encode(['status' => 'success', 'preview' => $result['preview']]);
    exit;
}

// ACTION 3 - push_json
if (isset($_GET['action']) && $_GET['action'] === 'push_json') {
    $targetUrl = $_POST['url'] ?? '';
    if (!$targetUrl) {
        echo json_encode(['status' => 'error', 'message' => 'Missing url'], JSON_PRETTY_PRINT);
        exit;
    }

    // block when type mismatch between Orion & CSV 
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

    // proceed with normal push (either CSV or Orion source)
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
        echo json_encode(['status' => 'error', 'message' => 'No JSON data stored in session (CSV and Orion)'], JSON_PRETTY_PRINT);
        exit;
    }
    $payload = json_encode($jsonData, JSON_UNESCAPED_UNICODE);

    app_log("ACTION push_json START url=" . $targetUrl, "INFO");

    $ch = curl_init($targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Content-Length: " . strlen($payload)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    app_log(sprintf("ACTION push_json RESULT http_code=%d error=%s", $code, $err ? $err : 'none'), $err ? 'ERROR' : 'INFO');

    if ($err) {
        echo json_encode(['status' => 'error', 'error' => $err], JSON_PRETTY_PRINT);
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

// ACTION 4 - get_mapping
if (isset($_GET['action']) && $_GET['action'] === 'get_mapping') {
    header("Content-Type: application/json; charset=utf-8");
    app_log("ACTION get_mapping START", "INFO");
    $map = load_attribute_map();
    if (empty($map)) {
        echo json_encode(['status' => 'error', 'message' => 'Mapping file missing or unreadable'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    app_log("ACTION get_mapping SUCCESS count=" . count($map), "INFO");

    echo json_encode(['status' => 'success', 'map' => $map], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ACTION 5 — Delete attribute (Orion DELETE + mapping removal)
if (isset($_GET['action']) && $_GET['action'] === 'orion_delete_attr') {
    $attrKey = $_POST['attrKey'] ?? '';
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $attrKey)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid attrKey']);
        exit;
    }

    app_log("ACTION orion_delete_attr START key=" . $attrKey, "INFO");

    $orion = orion_delete_attr($attrKey);
    // Treat 404 as success (attribute absent = idempotent)
    if ($orion['error']) {
        echo json_encode(['status' => 'error', 'message' => 'Orion DELETE failed', 'detail' => $orion], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($orion['http_code'] >= 500) {
        echo json_encode(['status' => 'error', 'message' => 'Orion DELETE server error', 'detail' => $orion], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $removed = remove_mapping_by_key($attrKey);
    echo json_encode([
        'status'          => 'success',
        'http_code'       => $orion['http_code'],
        'deleted_key'     => $attrKey,
        'removed_mapping' => $removed
    ], JSON_UNESCAPED_UNICODE);

    app_log(sprintf("ACTION orion_delete_attr RESULT http_code=%d removed_mapping=%s",
        $orion['http_code'], $removed ? 'true' : 'false'), 'INFO');
    exit;
}


// ACTION 6 — Add attribute (ensure entity via GET/CREATE, then POST)
if (isset($_GET['action']) && $_GET['action'] === 'orion_add_attr') {
    $jpName    = trim($_POST['jpName']    ?? '');
    $attrKey   = trim($_POST['attrKey']   ?? '');
    $attrType  = trim($_POST['attrType']  ?? '');
    $attrValue = $_POST['attrValue'] ?? '';

    // Basic validations
    if ($jpName === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $attrKey)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid jpName or attrKey'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $validTypes = ['Text', 'Integer', 'Float', 'DateTime'];
    if (!in_array($attrType, $validTypes, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid attrType'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- Duplicate checks against mapping file ----
    $map = load_attribute_map();
    // (1) Duplicate jpName check
    $jpExists = false;
    if (isset($map[$jpName])) {
        $jpExists = true;
    } else {
        // remove all whitespace to mimic mapper.php matching
        $normalizedInput = preg_replace('/\s+/', '', $jpName);
        foreach ($map as $existingJp => $info) {
            $normalizedExisting = preg_replace('/\s+/', '', $existingJp);
            if ($normalizedExisting === $normalizedInput) { $jpExists = true; break; }
        }
    }
    if ($jpExists) {
        echo json_encode([
            'status'  => 'error',
            'message' => "同じ項目名がすでに登録されています。\n別名に変更するか、既存項目を削除してください。"
        ], JSON_UNESCAPED_UNICODE);
        exit; // ABORT: do not POST to Orion
    }

    // (2) Duplicate key check (case-insensitive)
    $existingJpForKey = find_jp_name_for_key($attrKey); // returns jpName or null
    if ($existingJpForKey !== null) {
        echo json_encode([
            'status'  => 'error',
            'message' => "同じシステム項目名がすでに登録されています（既存の項目名：{$existingJpForKey}）。\n別のシステム項目名を使用するか、既存項目を削除してください。"
        ], JSON_UNESCAPED_UNICODE);
        exit; // ABORT: do not POST to Orion
    }

    // ---- If no duplicates, continue with normal add flow ----
    app_log(sprintf("ACTION orion_add_attr START key=%s type=%s", $attrKey, $attrType), 'INFO');

    // Ensure entity exists
    $probe = orion_get_entity();
    if ($probe['error']) {
        echo json_encode(['status' => 'error', 'message' => 'Orion GET failed', 'detail' => $probe], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($probe['http_code'] === 404) {
        $create = orion_create_entity();
        if ($create['error'] || ($create['http_code'] < 200 || $create['http_code'] >= 300)) {
            echo json_encode(['status' => 'error', 'message' => 'Entity create failed', 'detail' => $create], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif ($probe['http_code'] >= 500) {
        echo json_encode(['status' => 'error', 'message' => 'Orion GET server error', 'detail' => $probe], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Coerce typed value (Integer/Float/DateTime/Text)
    [$ok, $coerced] = coerce_typed_value($attrType, $attrValue);
    if (!$ok) {
        echo json_encode(['status' => 'error', 'message' => "Value/type mismatch: {$coerced}"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $typedAttr = [
        $attrKey => [
            'type'  => $attrType,
            'value' => $coerced
        ]
    ];

    // POST (create new attribute or update if exists — but we already blocked duplicates above)
    $post = orion_post_attrs($typedAttr);
    if ($post['error']) {
        echo json_encode(['status' => 'error', 'message' => 'Orion POST failed', 'detail' => $post], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($post['http_code'] >= 200 && $post['http_code'] < 300) {
        $added = add_mapping_entry($jpName, $attrKey, $attrType);
        echo json_encode([
            'status'    => 'success',
            'http_code' => $post['http_code'],
            'added'     => ['jpName' => $jpName, 'key' => $attrKey, 'type' => $attrType],
            'mapping'   => $added ? 'updated' : 'not-updated'
        ], JSON_UNESCAPED_UNICODE);
        app_log(sprintf("ACTION orion_add_attr SUCCESS http_code=%d mapping=%s",
            $post['http_code'], $added ? 'updated' : 'not-updated'), 'INFO');
        exit;
    }

    // Non-2xx
    echo json_encode([
        'status'  => 'error',
        'message' => 'Orion POST non-2xx (attribute may already exist or type conflict). Use a different Key or delete the attribute first.',
        'detail'  => $post
    ], JSON_UNESCAPED_UNICODE);
    app_log(sprintf("ACTION orion_add_attr ERROR http_code=%d error=%s",
        $post['http_code'] ?? 0, $post['error'] ?? 'none'), 'ERROR');
    exit;
}

// ACTION 7 - DEFAULT
echo json_encode(['status' => 'error', 'message' => 'Invalid action'], JSON_PRETTY_PRINT);
exit;