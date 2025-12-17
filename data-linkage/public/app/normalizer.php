<?php

// Detect a simple type for a raw value.
function detect_value_type($v) {
    if (is_int($v)) return 'Integer';
    if (is_float($v)) return 'Float';

    // Convert to string for regex checks
    $s = trim((string)$v);

    // ISO 8601 datetime 
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(Z|[+\-]\d{2}:\d{2})?$/', $s)) {
        return 'DateTime';
    }

    // Integer
    if (preg_match('/^\-?\d+$/', $s)) return 'Integer';

    // Float/Number
    if (preg_match('/^\-?\d+\.\d+$/', $s)) return 'Float';

    // Default
    return 'Text';
}

/**
 * Convert an ISO-8601 datetime string to "YYYY/MM/DD".
 * Returns null if parsing fails.
 */
function iso_datetime_to_yyyy_slash_mm_slash_dd($iso)
{
    if ($iso === null) return null;
    $s = trim((string)$iso);
    if ($s === '') return null;

    // Prefer DateTime parsing
    $dt = date_create($s);
    if ($dt instanceof \DateTime) {
        return $dt->format('Y/m/d');
    }

    // Fallback using strtotime
    $ts = @strtotime($s);
    if ($ts !== false && $ts > 0) {
        return date('Y/m/d', $ts);
    }

    return null;
}

/**
 * Normalize NGSIv2 entities to Format B.
 * - Preserves id/type.
 * - Attributes:
 *   * If Orion attr type is DateTime, convert to Text + "YYYY/MM/DD".
 *   * If raw value looks like DateTime, convert likewise.
 */
function normalize_ngsi_entities(array $entities)
{
    $out = [];

    foreach ($entities as $e) {
        $entry = [
            'id'        => $e['id'] ?? '',
            'type'      => $e['type'] ?? '',
            'attributes'=> []
        ];

        foreach ($e as $attrName => $attrData) {
            if ($attrName === 'id' || $attrName === 'type') {
                continue;
            }

            // Case 1: Typed NGSI attribute (Orion-style with 'type' and 'value')
            if (is_array($attrData) && isset($attrData['type']) && array_key_exists('value', $attrData)) {
                $attrType  = (string)$attrData['type'];
                $attrValue = $attrData['value'];

                // Convert DateTime -> Text (YYYY/MM/DD)
                if (strcasecmp($attrType, 'DateTime') === 0) {
                    $converted = iso_datetime_to_yyyy_slash_mm_slash_dd($attrValue);
                    if ($converted !== null) {
                        $entry['attributes'][$attrName] = [
                            'type'  => 'Text',
                            'value' => $converted
                        ];
                        continue;
                    }
                }
                elseif (strcasecmp($attrType, 'Number') === 0) {
                    $entry['attributes'][$attrName] = [
                        'type'=> 'Float',
                        'value'=> $attrValue
                    ];
                    continue;
                }

                // Otherwise keep original
                $entry['attributes'][$attrName] = [
                    'type'  => $attrType,
                    'value' => $attrValue
                ];
                continue;
            }

            // Case 2: Raw primitive attribute (no explicit NGSI typing)
            $detected = detect_value_type($attrData);
            if ($detected === 'DateTime') {
                $converted = iso_datetime_to_yyyy_slash_mm_slash_dd($attrData);
                if ($converted !== null) {
                    $entry['attributes'][$attrName] = [
                        'type'  => 'Text',
                        'value' => $converted
                    ];
                    continue;
                }
            }

            // Default handling
            $entry['attributes'][$attrName] = [
                'type'  => $detected,
                'value' => $attrData
            ];
        }

        $out[] = $entry;
    }

    return $out;
}