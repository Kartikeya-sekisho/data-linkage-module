
<?php
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/mapper.php';
require_once __DIR__ . '/normalizer.php';
require_once __DIR__ . '/validator.php';

// Append a debug line with timestamp
function csv_debug_add(array &$dbg, $msg): void {
  $time = date('Y-m-d H:i:s');
  $dbg[] = "[$time] $msg";
}

// Read CSV with multi-encoding support and auto delimiter detection
function read_csv_multiencoding_debug($filepath, array &$debug) {
  csv_debug_add($debug, "read_csv_multiencoding_debug called for path: $filepath");
  if (!file_exists($filepath)) {
    csv_debug_add($debug, "file does not exist");
    throw new Exception("CSV file does not exist.");
  }
  $stat = @stat($filepath);
  $size = $stat ? ($stat['size'] ?? null) : null;
  csv_debug_add($debug, "file exists. size_bytes=" . ($size === null ? 'unknown' : $size));
  $raw = file_get_contents($filepath);
  if ($raw === false) {
    csv_debug_add($debug, "file_get_contents returned false");
    throw new Exception("Unable to read uploaded file");
  }
  $first64 = substr($raw, 0, 64);
  csv_debug_add($debug, "first64_bytes (hex): " . bin2hex($first64));

  $encoding = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'SJIS', 'CP932', 'EUC-JP', 'ISO-2022-JP'], true);
  csv_debug_add($debug, "mb_detect_encoding result: " . ($encoding === false ? 'false' : $encoding));
  if ($encoding === false) {
    $encoding = 'SJIS-win';
    csv_debug_add($debug, "encoding fallback to: $encoding");
  }
  $utf8 = @mb_convert_encoding($raw, 'UTF-8', $encoding);
  if ($utf8 === false) {
    csv_debug_add($debug, "mb_convert_encoding returned false");
    throw new Exception("Failed to convert file to UTF-8 from encoding: $encoding");
  }

  // Normalize newlines
  $utf8 = str_replace(["\r\n", "\r"], "\n", $utf8);
  $nlpos = strpos($utf8, "\n");
  $firstLine = ($nlpos === false) ? $utf8 : substr($utf8, 0, $nlpos);
  csv_debug_add($debug, "first_line_preview: " . trim($firstLine));
  $lines = explode("\n", trim($utf8));
  csv_debug_add($debug, "total_lines_count: " . count($lines));
  if (count($lines) === 0) {
    csv_debug_add($debug, "no lines after splitting -> abort");
    return [[], $debug];
  }

  // Detect delimiter by counting characters in header
  $delim = "\t";
  $countComma = substr_count($firstLine, ",");
  $countTab   = substr_count($firstLine, "\t");
  $countSemi  = substr_count($firstLine, ";");
  if     ($countComma > $countTab && $countComma >= $countSemi) $delim = ",";
  elseif ($countSemi  > $countTab && $countSemi  >  $countComma) $delim = ";";
  else                                                           $delim = "\t";
  $detected = ($delim === "\t" ? 'TAB' : ($delim === ',' ? 'COMMA' : ($delim === ';' ? 'SEMICOLON' : 'UNKNOWN')));
  csv_debug_add($debug, "detected_delimiter: " . $detected);

  // Parse into a matrix
  $rows = [];
  foreach ($lines as $lineNo => $line) {
    if (trim($line) === '') continue;
    $cols = str_getcsv($line, $delim);
    // If delimiter is wrong and tabs exist, reparse with TAB
    if (count($cols) === 1 && $delim !== "\t" && strpos($line, "\t") !== false) {
      $cols = str_getcsv($line, "\t");
      csv_debug_add($debug, "fallback parsing line " . ($lineNo + 1) . " with TAB: columns=" . count($cols));
    }
    // Strip UTF-8 BOM from first cell
    if (isset($cols[0])) {
      $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cols[0]);
    }
    $rows[] = $cols;
  }
  csv_debug_add($debug, "parsed_matrix_rows=" . count($rows) . ", sample_row0_cols=" . (isset($rows[0]) ? count($rows[0]) : 0));
  if (isset($rows[0])) {
    csv_debug_add($debug, "headers_detected=" . json_encode($rows[0], JSON_UNESCAPED_UNICODE));
  }
  return [$rows, $debug];
}

// Convert matrix to associative rows keyed by header cells
function csv_to_assoc_debug(array $rows, array &$debug): array {
  if (count($rows) < 2) {
    csv_debug_add($debug, "csv_to_assoc_debug: not enough rows (count < 2)");
    throw new Exception("CSV does not contain enough rows.");
  }
  $headers = array_map('trim', $rows[0]);
  csv_debug_add($debug, "csv_to_assoc_debug: headers_trimmed=" . json_encode($headers, JSON_UNESCAPED_UNICODE));
  $out = [];
  for ($i = 1; $i < count($rows); $i++) {
    $row = $rows[$i];
    if (!is_array($row)) {
      csv_debug_add($debug, "csv_to_assoc_debug: skipping non-array row at index $i");
      continue;
    }
    $assoc = [];
    foreach ($headers as $c => $h) {
      $assoc[$h] = isset($row[$c]) ? $row[$c] : "";
    }
    $out[] = $assoc;
  }
  csv_debug_add($debug, "csv_to_assoc_debug: produced_assoc_count=" . count($out));
  return $out;
}

// Validate required headers, datatype compatibility, and duplicate display names
function validate_jp_csv_debug(array $rows, array &$debug): array {
  if (empty($rows)) {
    csv_debug_add($debug, "validate_jp_csv_debug: rows empty");
    return ['ok' => false, 'errors' => ['CSVが空です'], 'rows' => []];
  }
  $headerKeys = array_keys($rows[0]);
  $required   = ['項目名', '設定する値'];
  $missing    = array_diff($required, $headerKeys);
  if (!empty($missing)) {
    csv_debug_add($debug, "validate_jp_csv_debug: missing required columns=" . json_encode($missing, JSON_UNESCAPED_UNICODE));
    return [
      'ok'     => false,
      'errors' => ["必須ヘッダが不足しています: " . implode(', ', $missing)],
      'rows'   => $rows
    ];
  }
  $extras = array_diff($headerKeys, $required);
  if (!empty($extras) || count($headerKeys) !== count($required)) {
    csv_debug_add($debug, "validate_jp_csv_debug: unexpected headers=" . json_encode($extras, JSON_UNESCAPED_UNICODE));
    return [
      'ok'     => false,
      'errors' => ["CSVヘッダに認識されない列があります: " . implode(', ', $extras ?: $headerKeys)],
      'rows'   => $rows
    ];
  }

  $errors = [];
  foreach ($rows as $i => $row) {
    $line   = $i + 2;
    $value  = isset($row['設定する値']) ? $row['設定する値'] : '';
    $det    = detect_value_type($value);
    csv_debug_add($debug, "validate_line {$line}: detected_type={$det}, raw_value=" . (string)$value);
    [$ok, $msg] = validate_value_by_type($det, $value);
    if (!$ok) {
      $errors[] = "行{$line}: {$msg}";
      csv_debug_add($debug, "validate_jp_csv_debug: error line={$line} msg={$msg}");
    }
  }

  // Duplicate 項目名 detection
  $nameLines = [];
  foreach ($rows as $i => $row) {
    $line = $i + 2;
    $jpName = isset($row['項目名']) ? trim($row['項目名']) : '';
    if ($jpName === '') continue;
    $nameLines[$jpName] = isset($nameLines[$jpName]) ? $nameLines[$jpName] : [];
    $nameLines[$jpName][] = $line;
  }
  foreach ($nameLines as $name => $lines) {
    if (count($lines) > 1) {
      $errors[] = "同一の「項目名」が複数行に存在します: {$name} (行: " . implode(', ', $lines) . ")";
      csv_debug_add($debug, "validate_jp_csv_debug: duplicate name='{$name}' lines=" . implode(', ', $lines));
    }
  }

  $ok = count($errors) === 0;
  if (!$ok) app_log("CSV_VALIDATE_ERROR: " . implode(' | ', $errors), 'ERROR');
  return ['ok' => $ok, 'errors' => $errors, 'rows' => $rows];
}

// Convert CSV rows to Format-B (single flat entity); duplicates allowed.
// If multiple rows map to the same key, the last parsed value wins.
function convert_csv_to_format_b(array $rows, array &$debug): array {
  csv_debug_add($debug, "convert_csv_to_format_b: START (flat entity)");
  $entity = [
    'id'         => 'urn.ngsi-Id.TsukubaAnalytics.HomePage.20250201_001',
    'type'       => 'TsukubaAnalytics',
    'attributes' => []
  ];
  $skipped = [];
  foreach ($rows as $idx => $row) {
    $jpName = isset($row['項目名']) ? trim($row['項目名']) : '';
    $value  = isset($row['設定する値']) ? $row['設定する値'] : '';
    if ($jpName === '') {
      csv_debug_add($debug, "convert_csv_to_format_b: skipping empty jp_name at row $idx");
      continue;
    }
    $mapping = find_mapping_for_jp_name($jpName);
    if (!$mapping || empty($mapping['key'])) {
      $skipped[] = $jpName;
      csv_debug_add($debug, "convert_csv_to_format_b: no mapping for '{$jpName}', SKIP");
      continue;
    }
    $engKey    = $mapping['key'];
    $valueType = detect_value_type($value);
    // Last win semantics for same key
    $entity['attributes'][$engKey] = ['type' => $valueType, 'value' => $value];
    csv_debug_add($debug, "convert_csv_to_format_b: mapped '{$jpName}' -> key='{$engKey}', type='{$valueType}'");
  }
  $entities = [$entity];
  csv_debug_add($debug, "convert_csv_to_format_b: attributes_count=" . count($entity['attributes']));
  return ['entities' => $entities, 'skipped' => $skipped];
}

// Master pipeline: read, parse, validate; on validation error return preview + errors
function run_csv_pipeline_debug($csvFilepath) {
  $debug = [];
  try {
    csv_debug_add($debug, "run_csv_pipeline_debug START");
    list($matrix, $debug) = read_csv_multiencoding_debug($csvFilepath, $debug);
    if (!is_array($matrix) || count($matrix) === 0) {
      csv_debug_add($debug, "matrix empty after read");
      app_log("CSV_PIPELINE SUMMARY status=error message=CSVに有効行がありません。ヘッダとデータを確認してください。", 'ERROR');
      return [
        'status'  => 'error',
        'message' => 'CSVに有効行がありません。ヘッダとデータを確認してください。',
        'errors'  => ['CSVに有効行がありません。ヘッダとデータを確認してください。'],
        'preview' => [],
        'debug'   => $debug
      ];
    }

    $rows = csv_to_assoc_debug($matrix, $debug);
    $validation = validate_jp_csv_debug($rows, $debug);

    // If validation failed, still produce a preview and return along with errors
    if (!$validation['ok']) {
      csv_debug_add($debug, "validation failed: " . json_encode($validation['errors'], JSON_UNESCAPED_UNICODE));
      $conv    = convert_csv_to_format_b($validation['rows'], $debug);
      $formatB = $conv['entities'];
      $skipped = $conv['skipped'] ?? [];

      if (session_status() !== PHP_SESSION_ACTIVE) session_start();
      $_SESSION['latest_csv_json'] = $formatB;

      $entityCount = is_array($formatB) ? count($formatB) : 0;
      $attrCount = 0;
      foreach (($formatB ?? []) as $e) { $attrCount += count($e['attributes'] ?? []); }
      app_log(sprintf(
        "CSV_PIPELINE SUMMARY status=error(preview) entities=%d attributes=%d skipped=%d; details=%s",
        $entityCount, $attrCount, count($skipped), implode(' | ', $validation['errors'])
      ), 'ERROR');

      return [
        'status'  => 'error',
        'message' => 'Validation failed',
        'errors'  => $validation['errors'],
        'preview' => $formatB,
        'skipped_jp_names' => $skipped,
        'debug'   => $debug
      ];
    }

    // Success path: convert and store preview
    $conv    = convert_csv_to_format_b($validation['rows'], $debug);
    $formatB = $conv['entities'];
    $skipped = $conv['skipped'] ?? [];

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['latest_csv_json'] = $formatB;

    $entityCount = is_array($formatB) ? count($formatB) : 0;
    $attrCount = 0;
    foreach (($formatB ?? []) as $e) { $attrCount += count($e['attributes'] ?? []); }
    app_log(sprintf("CSV_PIPELINE SUMMARY status=success entities=%d attributes=%d, skipped=%d", $entityCount, $attrCount, count($skipped)), 'INFO');

    return ['status' => 'success', 'preview' => $formatB, 'skipped_jp_names' => $skipped, 'debug' => $debug];
  } catch (Exception $ex) {
    csv_debug_add($debug, "EXCEPTION: " . $ex->getMessage());
    app_log("CSV_PIPELINE SUMMARY status=error message=" . $ex->getMessage(), 'ERROR');
    return [
      'status'  => 'error',
      'message' => 'CSV processing failed: ' . $ex->getMessage(),
      'errors'  => ['CSV processing failed: ' . $ex->getMessage()],
      'preview' => [],
      'debug'   => $debug
       ];
  }
}