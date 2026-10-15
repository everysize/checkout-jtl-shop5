<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout;

/**
 * Amount typed into the refund form: "10", "10,5", "10.50", "1.234,56" or
 * "1,234.56" — the last comma or dot is the decimal separator if it is
 * followed by one or two digits. Returns the API form "1234.56", or null for
 * anything else (also for 0 and negative amounts). A single separator before
 * three digits ("10,555", "1.234") is refused: thousands or decimals is a
 * guess, and a wrong guess refunds a thousand times too much or too little.
 */
final class RefundAmount
{
    public static function normalize(string $input): ?string
    {
        $value = str_replace([' ', "\u{00A0}", '€'], '', trim($input));
        if (preg_match('/^\d+[.,]\d{3}$/', $value)) {
            return null;
        }
        if (!preg_match('/^\d{1,3}(?:[.,]?\d{3})*(?:[.,]\d{1,2})?$|^\d+(?:[.,]\d{1,2})?$/', $value)) {
            return null;
        }

        $decimals = '';
        if (preg_match('/[.,](\d{1,2})$/', $value, $match)) {
            $decimals = $match[1];
            $value = substr($value, 0, -\strlen($match[0]));
        }
        $integer = str_replace(['.', ','], '', $value);
        $amount = $integer . '.' . str_pad($decimals, 2, '0');
        $amount = ltrim($amount, '0');
        $amount = str_starts_with($amount, '.') ? '0' . $amount : $amount;

        return (float) $amount > 0 ? $amount : null;
    }
}
