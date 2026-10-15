<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Import;

/**
 * Turns the gross amounts everysize charged into the net/VAT figures the shop
 * stores on an order. everysize sends gross prices only; the VAT rate comes
 * from the shop (article's tax class for the delivery country).
 */
final class PriceCalculator
{
    /**
     * @param array<int, array{gross: float, quantity: int, vat: float}> $items unit gross price, quantity, VAT rate
     *
     * @return array{
     *     items: array<int, array{unitGross: float, unitNet: float, totalGross: float, totalNet: float, totalVat: float, vat: float}>,
     *     totalGross: float,
     *     totalNet: float,
     *     vatGroups: array<string, float>,
     *     deliveryCost: float,
     *     deliveryVat: float,
     *     totalOrderSum: float
     * }
     */
    public static function calculate(array $items, float $shippingGross): array
    {
        $result = [
            'items' => [],
            'totalGross' => 0.0,
            'totalNet' => 0.0,
            'vatGroups' => [],
            'deliveryCost' => round($shippingGross, 2),
            'deliveryVat' => 0.0,
            'totalOrderSum' => 0.0,
        ];

        foreach ($items as $key => $item) {
            $vat = (float) $item['vat'];
            $unitGross = round($item['gross'], 2);
            // JTL stores fPreis/fPreisEinzelNetto unrounded (Bestellung.php
            // multiplies fPreis * nAnzahl): rounding happens on the sums only.
            $unitNet = $unitGross / (1 + $vat / 100);
            $totalGross = round($unitGross * $item['quantity'], 2);
            $totalNet = round($unitNet * $item['quantity'], 2);
            $totalVat = round($totalGross - $totalNet, 2);

            $result['items'][$key] = [
                'unitGross' => $unitGross,
                'unitNet' => self::net($unitGross, $vat),
                'totalGross' => $totalGross,
                'totalNet' => $totalNet,
                'totalVat' => $totalVat,
                'vat' => $vat,
            ];

            $result['totalGross'] += $totalGross;
            $result['totalNet'] += $totalNet;
            $rate = self::rateKey($vat);
            $result['vatGroups'][$rate] = round(($result['vatGroups'][$rate] ?? 0.0) + $totalVat, 2);
            // Shipping carries the VAT rate of the items; an everysize order has one item, with several the highest.
            $result['deliveryVat'] = max($result['deliveryVat'], $vat);
        }

        $result['totalGross'] = round($result['totalGross'], 2);
        $result['totalNet'] = round($result['totalNet'], 2);
        $result['totalOrderSum'] = round($result['totalGross'] + $result['deliveryCost'], 2);
        krsort($result['vatGroups'], \SORT_NUMERIC);

        return $result;
    }

    /**
     * The stored order total must match the amount everysize charged: sum of
     * all positions plus shipping exactly = grossPrice. Returns a warning for
     * the protocol when it does not (more than a cent off), null if it fits.
     *
     * @param array{totalOrderSum: float} $prices as returned by calculate()
     */
    public static function checkTotal(array $prices, float $everysizeTotal, string $everysizeOrderId = ''): ?string
    {
        if (abs($everysizeTotal - $prices['totalOrderSum']) > 0.009) {
            return sprintf(
                '%s: total %.2f differs from everysize grossPrice %.2f',
                $everysizeOrderId,
                $prices['totalOrderSum'],
                $everysizeTotal
            );
        }

        return null;
    }

    private static function net(float $gross, float $vat): float
    {
        return $gross / (1 + $vat / 100);
    }

    private static function rateKey(float $vat): string
    {
        return rtrim(rtrim(number_format($vat, 2, '.', ''), '0'), '.');
    }
}
