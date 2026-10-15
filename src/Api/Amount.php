<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Api;

/**
 * Amounts in the everysize order JSON are strings produced by PHP's
 * number_format(): "119.99", but "1,234.56" from 1000 EUR on — the comma is a
 * thousands separator, not a decimal one. Computing with the raw string turns
 * "1,234.56" into 1.0.
 */
final class Amount
{
    private const PATTERN = '/^-?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?$/';

    public static function parse($value, string $field = 'amount'): float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        if (\is_string($value) && preg_match(self::PATTERN, trim($value))) {
            return (float) str_replace(',', '', trim($value));
        }

        throw new ApiException(sprintf('Invalid %s in everysize order: %s', $field, var_export($value, true)));
    }
}
