<?php

// use preg match to validate value for integer, float, date&time, etc.

function is_integer_value($v) {
    return preg_match('/^-?\d+$/', trim((string)$v)) === 1;
}

function is_float_value($v) {
    return preg_match('/^-?\d+\.\d+$/', trim((string)$v)) === 1;
}

function is_datetime_value($v) {
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', trim((string)$v)) === 1;
}

function validate_value_by_type(string $expedtedType, $value): array {
    $expedtedType = mb_strtolower(trim($expedtedType));
    if ($expedtedType === 'integer') {
        if (is_integer_value($value)) return [true, null];
        return [false, "Value: '$value' is not integer"];
    }
    if ($expedtedType === 'float') {
        if (is_float_value($value)) return [true, null];
        return [false, "Value: '$value' is not float"];
    }
    if ($expedtedType === 'date' || $expedtedType === 'datetime') {
        if (is_datetime_value($value)) return [true, null];
        return [false, "Value: '$value' is not datetime"];
    }
    return [true, "Fallback to string, marked as okay."];
}