<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Import;

use Plugin\everysize_checkout\Api\Client;

/**
 * The import report: what the shop had for an ordered position at import
 * time. Only informs — a failed read or send never touches the order.
 */
final class ImportReport
{
    /**
     * Cancelled orders are not reported.
     */
    public static function isReportable(string $status): bool
    {
        return \in_array(strtoupper($status), [Client::STATUS_PROCESSING, Client::STATUS_COMPLETED], true);
    }

    /**
     * Stock before the import in sellable items (JTL counts packed units:
     * fLagerbestand / fPackeinheit), null if the shop does not limit sales
     * by it (stock not watched, or a negative stock is allowed).
     */
    public static function stock(string $watchStock, string $allowNegative, float $stock, float $packUnit): ?int
    {
        if ($watchStock !== 'Y' || $allowNegative === 'Y') {
            return null;
        }

        return (int) floor($stock / ($packUnit > 0 ? $packUnit : 1.0));
    }

    /**
     * @return array{sku: string, shopPrice: ?string, stock: ?int}
     */
    public static function entry(string $sku, ?float $shopPrice, ?int $stock): array
    {
        return [
            'sku' => $sku,
            'shopPrice' => $shopPrice !== null && is_finite($shopPrice) ? number_format($shopPrice, 2, '.', '') : null,
            'stock' => $stock,
        ];
    }

    /**
     * Warnings for one position. An existing stock warning is not repeated:
     * the importer passes $stockWarned = true then.
     *
     * @param array{sku: string, shopPrice: ?string, stock: ?int} $entry
     *
     * @return string[]
     */
    public static function warnings(string $everysizeOrderId, array $entry, float $paidGross, int $quantity, bool $stockWarned = false): array
    {
        $warnings = [];
        if ($entry['shopPrice'] !== null && abs((float) $entry['shopPrice'] - $paidGross) > 0.009) {
            $warnings[] = sprintf(
                '%s: %s: shop price %s differs from the price paid at everysize %.2f',
                $everysizeOrderId,
                $entry['sku'],
                $entry['shopPrice'],
                $paidGross
            );
        }
        if (!$stockWarned && $entry['stock'] !== null && $entry['stock'] < $quantity) {
            $warnings[] = sprintf(
                '%s: %s: stock %d is below the ordered quantity %d (oversold)',
                $everysizeOrderId,
                $entry['sku'],
                $entry['stock'],
                $quantity
            );
        }

        return $warnings;
    }

    /**
     * Sends one report per position, once. Any failure (also a 404 while the
     * checkout does not know the route yet) becomes a warning text.
     *
     * @param array<int, array{sku: string, shopPrice: ?string, stock: ?int}> $entries
     *
     * @return string[] warnings
     */
    public static function send(Client $client, string $everysizeOrderId, array $entries): array
    {
        $warnings = [];
        foreach ($entries as $entry) {
            try {
                $client->sendImportReport($everysizeOrderId, $entry['sku'], $entry['shopPrice'], $entry['stock']);
            } catch (\Throwable $e) {
                $warnings[] = sprintf('%s: %s: import report not sent: %s', $everysizeOrderId, $entry['sku'], $e->getMessage());
            }
        }

        return $warnings;
    }
}
