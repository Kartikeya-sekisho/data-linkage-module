<?php

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/mapper.php';
require_once __DIR__ . '/normalizer.php';

// Fetch raw JSON from Orion
function fetch_from_orion_service(
    string $service,
    string $servicePath = '/',
    int $timeout = 15,
    int $retries = 1,
    int $retry_sleep_ms = 200
) {
    $cfg = require __DIR__ . '/../../config/config.php';
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
                return [
                    'success'      => true,
                    'entities'     => $json,
                    'raw_response' => $raw
                ];
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

// Run pipeline
function run_orion_pipeline(array $opts = []) {

    $cfg = require __DIR__ . '/../../config/config.php';

    $service     = $opts['service']     ?? $cfg['fiware_service'];
    $servicePath = $opts['servicePath'] ?? $cfg['fiware_servicepath'];
    $timeout     = $cfg['timeout_seconds'] ?? 15;
    $retries     = $cfg['http_retries']    ?? 1;
    $sleep_ms    = $cfg['retry_sleep_ms']  ?? 200;

    // 1) fetch
    $fetch = fetch_from_orion_service($service, $servicePath, $timeout, $retries, $sleep_ms);
    if (!$fetch['success']) {
        $msg = "FETCH_ORION failed: " . ($fetch['error'] ?? 'unknown');
        app_log($msg, 'ERROR');
        return ['status' => 'error', 'message' => $msg];
    }

    $entities    = $fetch['entities'];
    $rawResponse = $fetch['raw_response'];

    // 2) Normalize
    $normalized = normalize_ngsi_entities($entities);
    // 3) Map -> preview rows
    $previewRows = build_preview_rows($normalized);
    // 4) store JSON in session to be send to external web app
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['latest_orion_json'] = $previewRows;

    $mappedFile = null;

    // logging info
    $totalEntities = count($normalized);
    $totalAttrs = 0;
    foreach ($normalized as $e) {
        $totalAttrs += count($e['attributes'] ?? []);
    }

    $logMsg = sprintf(
        "FETCH_ORION: Service=%s ServicePath=%s Entities=%d Attributes=%d",
        $service, $servicePath, $totalEntities, $totalAttrs
    );
    app_log($logMsg, 'INFO');

    // return preview + urls
    return [
        'status'  => 'success',
        'preview' => $previewRows
    ];
}