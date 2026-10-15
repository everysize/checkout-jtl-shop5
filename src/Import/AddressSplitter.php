<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Import;

/**
 * everysize sends street and house number in one field (addressLine1); JTL
 * keeps them apart (tadresse.cStrasse / cHausnummer). Splits off a trailing
 * house number ("12", "12a", "12 b", "5-7", "12/1"); anything else stays
 * complete in the street field rather than being split wrongly.
 */
final class AddressSplitter
{
    private const PATTERN = '/^(.*\S)\s+(\d+(?:\s?[a-zA-Z])?(?:\s?[-\/]\s?\d+(?:\s?[a-zA-Z])?)?)$/u';

    /**
     * @return array{0: string, 1: string} [street, house number]
     */
    public static function split(string $addressLine): array
    {
        $addressLine = trim(preg_replace('/\s+/u', ' ', $addressLine) ?? $addressLine);
        if (preg_match(self::PATTERN, $addressLine, $m)) {
            return [$m[1], $m[2]];
        }

        return [$addressLine, ''];
    }
}
