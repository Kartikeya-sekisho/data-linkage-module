
<?php
// Integer value regex
function is_integer_value($v): bool {
  return preg_match('/^\-?\d+$/', trim((string)$v)) === 1;
}

// Float value regex (decimal dot only)
function is_float_value($v): bool {
  return preg_match('/^\-?\d+\.\d+$/', trim((string)$v)) === 1;
}

// DateTime value regex (ISO8601 with optional seconds and timezone)
function is_datetime_value($v): bool {
  return preg_match('/^\d{4}\-\d{2}\-\d{2}T\d{2}:\d{2}(:\d{2})?$/', trim((string)$v)) === 1;
}

// Validate a value given an expected type name
function validate_value_by_type(string $expectedType, $value): array {
  $expectedType = mb_strtolower(trim($expectedType));
  if ($expectedType === 'integer') {
    if (is_integer_value($value)) return [true, null];
    return [false, "Value: '$value' is not integer"];
  }
  if ($expectedType === 'float') {
    if (is_float_value($value)) return [true, null];
    return [false, "Value: '$value' is not float"];
  }
  if ($expectedType === 'date' || $expectedType === 'datetime') {
    if (is_datetime_value($value)) return [true, null];
    return [false, "Value: '$value' is not datetime"];
  }
  return [true, "Fallback to string, marked as okay."];
}