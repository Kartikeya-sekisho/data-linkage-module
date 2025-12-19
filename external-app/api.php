
<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Simple file logger for external API
function logMessage($msg) {
  $timestamp = date('Y-m-d H:i:s');
  $logFile   = __DIR__ . '/api.log';
  file_put_contents($logFile, "[$timestamp] $msg" . PHP_EOL, FILE_APPEND);
}

// Handle preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit;
}

// Handle POST: receive Format-B array, flatten to lowercase-key object
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  logMessage("POST from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

  $rawInput = file_get_contents("php://input");
  logMessage("Raw length=" . strlen($rawInput));
  if (empty($rawInput)) {
    logMessage("ERROR: Empty POST body");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty request body'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $data = json_decode($rawInput, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage("ERROR: JSON decode failed - " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (!$data || !is_array($data)) {
    logMessage("ERROR: Invalid data structure");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or empty JSON'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $receivedFile = __DIR__ . "/received.json";
  $saveResult = file_put_contents($receivedFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  if ($saveResult === false) {
    logMessage("ERROR: Failed to save received.json");
  } else {
    logMessage("SUCCESS: Saved received.json ($saveResult bytes)");
  }

  $transformed  = [];
  $entityCount  = 0;
  foreach ($data as $entity) {
    if (!isset($entity['attributes']) || !is_array($entity['attributes'])) continue;
    $entityCount++;
    foreach ($entity['attributes'] as $attrKey => $attrData) {
      $value = $attrData['value'] ?? null;
      if ($value !== null) {
        $transformed[strtolower($attrKey)] = $value;
      }
    }
  }

  $dataFile = __DIR__ . "/data.json";
  $saveResult = file_put_contents($dataFile, json_encode($transformed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  if ($saveResult === false) {
    logMessage("ERROR: Failed to save data.json");
  } else {
    logMessage("SUCCESS: Saved data.json ($saveResult bytes)");
  }

  $attrCount = count($transformed);
  logMessage("SUCCESS: Processed $entityCount entities, extracted $attrCount attributes");
  http_response_code(200);
  echo json_encode([
    'status'               => 'success',
    'message'              => 'JSON received and processed',
    'entities_received'    => $entityCount,
    'attributes_extracted' => $attrCount
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// Handle GET: return transformed data object
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  logMessage("GET from " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

  $dataFile = __DIR__ . "/data.json";
  if (!file_exists($dataFile)) {
    logMessage("WARNING: data.json not found");
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No data available yet'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $contents = file_get_contents($dataFile);
  if ($contents === false) {
    logMessage("ERROR: Failed to read data.json");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to read data file'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $data = json_decode($contents, true);
  if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    logMessage("ERROR: data.json invalid JSON - " . json_last_error_msg());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to parse data'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (empty($data)) {
    $data = new stdClass(); // return empty object rather than array
  }

  logMessage("SUCCESS: Returned " . count((array)$data) . " attributes");
  http_response_code(200);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

// Method not allowed
logMessage("ERROR: Method not allowed - " . $_SERVER['REQUEST_METHOD']);
http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Allowed methods: GET, POST, OPTIONS'], JSON_UNESCAPED_UNICODE);