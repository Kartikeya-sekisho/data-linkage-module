
<?php
// Simple type detection for raw values
function detect_value_type($v) {
  if (is_int($v))   return 'Integer';
  if (is_float($v)) return 'Float';
  $s = trim((string)$v);
  if (preg_match('/^\d{4}\-\d{2}\-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(Z|[\+\-]\d{2}:\d{2})$/', $s)) return 'DateTime';
  if (preg_match('/^\-?\d+$/', $s))        return 'Integer';
  if (preg_match('/^\-?\d+\.\d+$/', $s))   return 'Float';
  return 'Text';
}

// Convert ISO8601 to "YYYY/MM/DD", return null if parsing fails
function iso_datetime_to_yyyy_slash_mm_slash_dd($iso) {
  if ($iso === null) return null;
  $s = trim((string)$iso);
  if ($s === '') return null;
  $dt = date_create($s);
  if ($dt instanceof \DateTime) {
    return $dt->format('Y/m/d');
  }
  $ts = @strtotime($s);
  if ($ts !== false && $ts > 0) {
    return date('Y/m/d', $ts);
  }
  return null;
}

// Normalize NGSIv2 entities into Format-B
function normalize_ngsi_entities(array $entities) {
  $out = [];
  foreach ($entities as $e) {
    $entry = [
      'id'         => $e['id']   ?? '',
      'type'       => $e['type'] ?? '',
      'attributes' => []
    ];
    foreach ($e as $attrName => $attrData) {
      if ($attrName === 'id' || $attrName === 'type') continue;

      // NGSI typed attribute (Orion style)
      if (is_array($attrData) && isset($attrData['type']) && array_key_exists('value', $attrData)) {
        $attrType  = (string)$attrData['type'];
        $attrValue = $attrData['value'];

        // DateTime -> Text with YYYY/MM/DD
        if (strcasecmp($attrType, 'DateTime') === 0) {
          $converted = iso_datetime_to_yyyy_slash_mm_slash_dd($attrValue);
          if ($converted !== null) {
            $entry['attributes'][$attrName] = ['type' => 'Text', 'value' => $converted];
            continue;
          }
        } elseif (strcasecmp($attrType, 'Number') === 0) {
          $entry['attributes'][$attrName] = ['type' => 'Float', 'value' => $attrValue];
          continue;
        }

        $entry['attributes'][$attrName] = ['type' => $attrType, 'value' => $attrValue];
        continue;
      }

      // Primitive attribute without NGSI typing
      $detected = detect_value_type($attrData);
      if ($detected === 'DateTime') {
        $converted = iso_datetime_to_yyyy_slash_mm_slash_dd($attrData);
        if ($converted !== null) {
          $entry['attributes'][$attrName] = ['type' => 'Text', 'value' => $converted];
          continue;
        }
      }

           $entry['attributes'][$attrName] = ['type' => $detected, 'value' => $attrData];
    }
    $out[] = $entry;
  }
  return $out;
}