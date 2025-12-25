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
  csv_debug_add($debug, "read_csv_multiencoding_debug: fgetcsv called for path-$filepath");

  if (!file_exists($filepath)) {
    csv_debug_add($debug, "file does not exist");
    throw new Exception("CSV file does not exist");
  }

  $stat = @stat($filepath);
  $size = $stat ? ($stat['size'] ?? null) : null;
  csv_debug_add($debug, "file exists, size bytes=" . ($size === null ? 'unknown' : $size));

  // 1) read raw bytes
  $raw = file_get_contents($filepath);
  if ($raw === false) {
    csv_debug_add($debug, "file_get_contents returned false");
    throw new Exception("Unable to read uploaded file");
  }

  // 2) detect encoding and convert
  $encoding = mb_detect_encoding($raw, ['UTF-8','SJIS-win','SJIS','CP932','EUC-JP','ISO-2022-JP'], true);
  csv_debug_add($debug, "mb_detect_encoding result:" . ($encoding === false ? 'false' : $encoding));
  if ($encoding === false) {
    $encoding = 'SJIS-win';
    csv_debug_add($debug, "encoding fallback to $encoding");
  }
  $utf8 = @mb_convert_encoding($raw, 'UTF-8', $encoding);
  if ($utf8 === false) {
    csv_debug_add($debug, "mb_convert_encoding returned false");
    throw new Exception("Failed to convert file to UTF-8 encoding from $encoding");
  }

  // No need to pre-split lines as fgets operates of stream
  // 3) write utf-8 in temp stream
  $fh = fopen('php://temp', 'r+');
  if (!$fh) {
    throw new Exception("Failed to open temp stream for CSV");
  }
  fwrite($fh, $utf8);
  rewind($fh);
  
  // 4) detect delimiter
  $candidates = [","=> "COMMA", ";"=> "SEMICOLON", "\t"=> "TAB"];
  $bestDelim = ",";
  $bestCols = null;
  $bestCount = -1;

  foreach ($candidates as $delim=> $label) {
    rewind($fh);
    $peek = fgetcsv($fh, 0, $delim, '"');
    if ($peek === false) {
      csv_debug_add($debug, "delimiter $label: fgetcsv returned false");
      continue;
    }
    // Normalize BOM in 1st cell
    if (isset($peek[0])) {
      $peek[0] = preg_replace('/^\xEF\xBB\xBF/', '', $peek[0]);
    }
    $count = count($peek);
    csv_debug_add($debug, "delimiter $label: first logical row columns=$count");

    // prefer candidate that yields >=2 columns and highest column count
    if ($count >= 2 && $count > $bestCount) {
      $bestCount = $count;
      $bestDelim = $delim;
      $bestCols = $peek;
    }
  }
  // fallback
  if ($bestCount < 2) {
    // try with comma
    rewind($fh);
    $bestDelim = ",";
    $bestCols = fgetcsv($fh, 0, $bestDelim, '"');
    if ($bestCols === false) {
      fclose($fh);
      csv_debug_add($debug, "no logical rows parsed; abort");
      return [[], $debug];
    }
    if (isset($bestCols[0])) {
      $bestCols[0] = preg_replace('/^\xEF\xBB\xEF/', '', $bestCols[0]);
    }
    $bestCount = count($bestCols);
  }

  // 5) Build rows, start with headers
  $rows = [];
  // normalize headers
  $normalize = function($s) {
    $s = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', (string)$s);
    return $s;
  };

  // normalize BOM for headers only
  if (isset($bestCols[0])) {
    $bestCols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $bestCols[0]);
  }
  foreach ($bestCols as $i=> $cell) {
    $bestCols[$i] = $normalize($cell);
  }
  $rows[] = $bestCols;

  // rest of the file
  while (($cols = fgetcsv($fh, 0, $bestDelim, '"')) !== false) {
    $allEmpty = true;
    foreach ($cols as $c) {
      if (trim((string)$c) !== '') { $allEmpty = false; break; }
    }
    if ($allEmpty) { continue; }
    $rows[] = $cols;
  }
  fclose($fh);

  // 6) Debug previews
  if (isset($rows[0])) {
    csv_debug_add($debug, "headers detected" . json_encode($rows[0], JSON_UNESCAPED_UNICODE));
  }
  csv_debug_add($debug, "parsed matrix rows=" . count($rows) . ", sample row0_cols="
   . (isset($rows[0]) ? count($rows[0]) : 0));

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
        return ['ok' => false, 'fatal_errors' => ['CSVが空です'], 'ui_errors' => [], 'rows' => []];
    }

    $headerKeys = array_keys($rows[0]);
    $required   = ['項目名', '設定する値'];
    $missing    = array_diff($required, $headerKeys);

    if (!empty($missing)) {
        csv_debug_add($debug, "validate_jp_csv_debug: missing required columns=" . json_encode($missing, JSON_UNESCAPED_UNICODE));
        return [
            'ok'          => false,
            'fatal_errors'=> ["必須ヘッダが不足しています: " . implode(', ', $missing)],
            'ui_errors'   => [],
            'rows'        => $rows
        ];
    }

    $extras = array_diff($headerKeys, $required);
    if (!empty($extras) || count($headerKeys) !== count($required)) {
        csv_debug_add($debug, "validate_jp_csv_debug: unexpected headers=" . json_encode($extras, JSON_UNESCAPED_UNICODE));
        return [
            'ok'          => false,
            'fatal_errors'=> ["CSVヘッダに認識されない列があります: " . implode(', ', $extras ?: $headerKeys)],
            'ui_errors'   => [],
            'rows'        => $rows
        ];
    }

    // Non-fatal UI buckets (kept)
    $uiErr = [
        'symbols'       => [],
        'missing_name'  => [],
        'type_mismatch' => [],
        'multiline'     => [],
    ];
    $fatalErrors = [];

    // ---- Normalization helper: mirror mapper whitespace behavior ----
    $normalizeJp = function($s) {
        $s = (string)$s;
        // Trim ASCII and full-width spaces from both ends
        $s = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $s);
        // Remove ALL whitespace inside (ASCII + full-width)
        $s = preg_replace('/[\s\x{3000}]+/u', '', $s);
        return $s;
    };

    // Collect line-based info ONLY for name duplicates (fatal)
    $nameLinesByNorm = [];   // normName -> ['displayNames'=>Set, 'lines'=>[int]]

    foreach ($rows as $i => $row) {
        $line   = $i + 2; // header is line 1
        $jpName = isset($row['項目名']) ? trim($row['項目名']) : '';
        $value  = isset($row['設定する値']) ? $row['設定する値'] : '';

        // value present but 項目名 missing (non-fatal)
        if ($jpName === '' && $value !== '') {
            $uiErr['missing_name'][] = ['line' => $line, 'value' => $value];
            csv_debug_add($debug, "validate_jp_csv_debug: line {$line} value present but 項目名 missing");
        }

        // Mapping/type detection (for existing non-fatal checks)
        $mapping      = ($jpName !== '') ? find_mapping_for_jp_name($jpName) : null;
        $expectedType = null;
        if (is_array($mapping) && isset($mapping['type']) && $mapping['type'] !== '') {
            $expectedType = (string)$mapping['type'];
        } else {
            $expectedType = detect_value_type($value);
        }

        // Non-fatal: symbols/slash check (Text)
        if (strcasecmp($expectedType, 'Text') === 0 && $value !== '') {
            $hasSymbols    = contains_unverified_symbols($value);
            $hasAsciiSlash = (strpos($value, '/') !== false);
            $validYMD      = $hasAsciiSlash ? is_yyyy_mm_dd($value) : false;
            if ($hasSymbols || ($hasAsciiSlash && !$validYMD)) {
                if ($jpName !== '') {
                    $uiErr['symbols'][] = $jpName;
                }
                csv_debug_add($debug, "validate_jp_csv_debug: symbol/slash(Text) jp={$jpName}, value={$value}");
            }
        }

        // Non-fatal: multiline detection
        $hasLineBreak = (strpos((string)$value, "\n") !== false) || (strpos((string)$value, "\r") !== false);
        if ($jpName !== '' && $hasLineBreak) {
            $uiErr['multiline'][] = ['line' => $line, '項目名' => $jpName];
            csv_debug_add($debug, "validate_jp_csv_debug: multiline value at jp='{$jpName}' line={$line}");
        }

        // Non-fatal: type mismatch check
        $det = detect_value_type($value);
        [$okType, $msg] = validate_value_by_type($det, $value);
        if (!$okType) {
            $uiErr['type_mismatch'][] = [
                'line'    => $line,
                '項目名'  => $jpName,
                'value'   => $value,
                'message' => $msg
            ];
            csv_debug_add($debug, "validate_jp_csv_debug: type-mismatch line: {$line}, message: {$msg}");
        }

        // ---- Collect data for fatal duplicate NAME checks (ONLY) ----
        if ($jpName !== '') {
            $norm = $normalizeJp($jpName);
            if (!isset($nameLinesByNorm[$norm])) {
                $nameLinesByNorm[$norm] = ['displayNames' => [], 'lines' => []];
            }
            // track display name variants
            $nameLinesByNorm[$norm]['displayNames'][$jpName] = true;
            $nameLinesByNorm[$norm]['lines'][] = $line;
        }

        // NOTE: we intentionally DO NOT collect or fatal-report key collisions anymore.
        // Key-collision fatal reporting has been REMOVED per requirement.
    }

    // ---- Fatal: duplicate 項目名 (normalized) ----
    foreach ($nameLinesByNorm as $norm => $info) {
        $lines = $info['lines'];
        if (count($lines) > 1) {
            $displayNames = array_keys($info['displayNames']);
            // Prefer the first display name for message, include variants if they differ
            $label = $displayNames[0];
            if (count($displayNames) > 1) {
                $label .= "（別表記: " . implode(', ', array_slice($displayNames, 1)) . "）";
            }
            $fatalErrors[] = "重複した項目名: {$label}（行" . implode(', 行', $lines) . "）";
            csv_debug_add($debug, "validate_jp_csv_debug: FATAL duplicate jpName norm='{$norm}' lines=" . implode(', ', $lines));
        }
    }

    $ok = count($fatalErrors) === 0;
    if (!$ok) app_log("CSV_VALIDATE_ERROR: " . implode(' | ', $fatalErrors), 'ERROR');

    return ['ok' => $ok, 'fatal_errors' => $fatalErrors, 'ui_errors' => $uiErr, 'rows' => $rows];
}

// Convert CSV rows to Format-B (single flat entity); duplicates not allowed.
function convert_csv_to_format_b(array $rows, array &$debug): array {
  csv_debug_add($debug, "convert_csv_to_format_b: START (flat entity)");
  $entity = [
    'id'         => 'urn.ngsi-Id.TsukubaAnalytics.HomePage.20250201_001',
    'type'       => 'TsukubaAnalytics',
    'attributes' => []
  ];
  $skipped = []; // Collect skipped 項目名

  foreach ($rows as $idx => $row) {
    $jpName = isset($row['項目名']) ? trim($row['項目名']) : '';
    $value  = isset($row['設定する値']) ? $row['設定する値'] : '';
    if ($jpName === '') {
      csv_debug_add($debug, "convert_csv_to_format_b: skipping empty jp_name at row $idx");
      continue;
    }

    // unmapped 項目名
    $mapping = find_mapping_for_jp_name($jpName);
    if (!$mapping || empty($mapping['key'])) {
      $skipped[] = $jpName;
      csv_debug_add($debug, "convert_csv_to_format_b: no mapping for '{$jpName}', SKIP");
      continue;
    }

    // if jpName in data but no value in data
    $isEmptyValue = preg_replace('/\s|\x{3000}/u', '', (string)$value) === '';
    if ($isEmptyValue) {
      csv_debug_add($debug, "convert_csv_to_format_b: found jpName={$jpName} with no value");
      continue;
    }

    $engKey    = $mapping['key'];
    $valueType = detect_value_type($value);
    $entity['attributes'][$engKey] = ['type' => $valueType, 'value' => $value];
    csv_debug_add($debug, "convert_csv_to_format_b: mapped '{$jpName}' -> key='{$engKey}', type='{$valueType}'");
  }
  $entities = [$entity];
  csv_debug_add($debug, "convert_csv_to_format_b: attributes_count=" . count($entity['attributes']));
  return ['entities' => $entities, 'skipped' => $skipped];
}

function building_preview_rows(array $rows, array &$debug): array {
  csv_debug_add($debug, "building_preview_rows: START");
  $out = [];
  foreach ($rows as $i => $row) {
    $jpName = isset($row['項目名']) ? trim($row['項目名']) : '';
    $value = isset($row['設定する値']) ? $row['設定する値'] : '';
    $mapping = $jpName !== '' ? find_mapping_for_jp_name($jpName) : null;
    $engKey = $mapping['key'] ?? null;

    if (trim($value) === '') {
      $out[] = [
        '項目名'=> $jpName,
        '取込データ（型）'=> '-',
        '取込データ（値）'=> '-',
        'mapped_key'=> $engKey
      ];
    } else {
      $type = detect_value_type($value);
      $out[] = [
        '項目名'=> $jpName,
        '取込データ（型）'=> $type,
        '取込データ（値）'=> $value,
        'mapped_key'=> $engKey
      ];
    }
  }
  csv_debug_add($debug, "building_preview_rows: produces_count=" . count($out));
  return $out;
}

function run_csv_pipeline_debug($csvFilepath) {
  $debug = [];
  try {
    csv_debug_add($debug, "run_csv_pipeline_debug START");
    list($matrix, $debug) = read_csv_multiencoding_debug($csvFilepath, $debug);
    if (!is_array($matrix) || count($matrix) === 0) {
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

    if (!$validation['ok']) {
      // Fatal errors -> no preview, clear session
      if (session_status() !== PHP_SESSION_ACTIVE) session_start();
      unset($_SESSION['latest_csv_json'], $_SESSION['latest_csv_has_errors'], $_SESSION['latest_csv_ui_errors']);
      app_log(sprintf("CSV_PIPELINE SUMMARY status=error entities=0 attributes=0; fatal=%s",
        implode(' | ', $validation['fatal_errors'])
      ), 'ERROR');

      return [
        'status'  => 'error',
        'message' => 'Validation failed',
        'errors'  => $validation['fatal_errors'],
        'preview' => [],
        'debug'   => $debug
      ];
    }

    // Success (non-fatal UI errors allowed, preview displayed)
    $conv        = convert_csv_to_format_b($validation['rows'], $debug);
    $formatB     = $conv['entities'];
    $skipped     = $conv['skipped'] ?? [];
    $previewRows = building_preview_rows($validation['rows'], $debug);

    // merge skipped into uiErrors
    $uiErrors = is_array($validation['ui_errors'] ?? null) 
      ? $validation['ui_errors']
      : ['symbols'=> [], 'missing_name'=> [], 'type_mismatch'=> []];
    $uiErrors['skipped'] = $skipped;
    if (!isset($uiErrors['multiline'])) $uiErrors['multiline'] = [];

    // mark session to BLOCK push_json when any non-fatal errors exist
    $hasNonFatal =
      !empty($uiErrors['symbols']) ||
      !empty($uiErrors['missing_name']) ||
      !empty($uiErrors['type_mismatch']) ||
      !empty($uiErrors['skipped']) ||
      !empty($uiErrors['multiline']);

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['latest_csv_json']      = $formatB;
    $_SESSION['latest_csv_has_errors'] = $hasNonFatal;           // push_json must block when true
    $_SESSION['latest_csv_ui_errors']  = $uiErrors;

    $entityCount = is_array($formatB) ? count($formatB) : 0;
    $attrCount   = 0;
    foreach (($formatB ?? []) as $e) { $attrCount += count($e['attributes'] ?? []); }
    
    app_log(sprintf(
      "CSV_PIPELINE SUMMARY status=success entities=%d attributes=%d; ui_symbols=%d ui_missing_name=%d ui_type_mismatch=%d skipped=%d",
      $entityCount, $attrCount,
      count($uiErrors['symbols'] ?? []),
      count($uiErrors['missing_name'] ?? []),
      count($uiErrors['type_mismatch'] ?? []),
      count($uiErrors['skipped'] ?? []),
      count($uiErrors['multiline'] ?? []) 
    ), 'INFO');

    return [
      'status'        => 'success',
      'preview'       => $formatB,            // for loading
      'preview_rows'  => $previewRows,        // for UI table ('-')
      'ui_errors'     => $uiErrors, // for dialogs
      'skipped_jp_names' => $skipped,
      'debug'         => $debug
    ];
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