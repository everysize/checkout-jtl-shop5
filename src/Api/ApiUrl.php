<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Api;

/**
 * Base URL of the everysize checkout API.
 *
 * The address comes from the environment variable EVERYSIZE_CHECKOUT_API_URL
 * or — JTL-specific — from a PHP constant of the same name, which a merchant
 * can define in includes/config.JTL-Shop.ini.php. Without both this is always
 * production, so a merchant installation talks to the live checkout without
 * any setup. A test or development shop sets the address of an everysize test
 * environment there; everysize hands it out together with the test
 * credentials.
 *
 * A value that is set must be https on a host under everysize.com, without
 * port or path. Anything else is an error and does NOT fall back to
 * production: a test shop with a typo must never send its orders to the live
 * checkout.
 */
final class ApiUrl
{
    public const PRODUCTION = 'https://ext-checkout.everysize.com';

    public const ENV = 'EVERYSIZE_CHECKOUT_API_URL';

    private const PATTERN = '~^https://([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+everysize\.com$~';

    /**
     * @param string|null $configured value to check; null reads the environment /
     *                                the PHP constant
     */
    public static function resolve(?string $configured = null): string
    {
        $value = strtolower(rtrim(trim($configured ?? self::fromEnvironment()), '/'));
        if ($value === '') {
            return self::PRODUCTION;
        }

        if (!preg_match(self::PATTERN, $value)) {
            throw new ApiException(sprintf('%s=%s is not allowed: expected https://<host>.everysize.com without port or path', self::ENV, $value));
        }

        return $value;
    }

    public static function isProduction(string $url): bool
    {
        return $url === self::PRODUCTION;
    }

    /**
     * Environment for the settings page: "production", "test" or "invalid".
     *
     * @return array{mode: string, url: string}
     */
    public static function describe(?string $configured = null): array
    {
        $raw = trim($configured ?? self::fromEnvironment());
        try {
            $url = self::resolve($raw);
        } catch (ApiException $e) {
            return ['mode' => 'invalid', 'url' => $raw];
        }

        return ['mode' => self::isProduction($url) ? 'production' : 'test', 'url' => $url];
    }

    private static function fromEnvironment(): string
    {
        foreach ([getenv(self::ENV), $_ENV[self::ENV] ?? null, $_SERVER[self::ENV] ?? null] as $value) {
            if (\is_string($value) && trim($value) !== '') {
                return $value;
            }
        }
        // JTL: merchants can define the constant in includes/config.JTL-Shop.ini.php
        if (\defined(self::ENV)) {
            $value = \constant(self::ENV);

            if (\is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }
}
