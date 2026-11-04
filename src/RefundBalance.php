<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout;

/**
 * The open refund balance of a payment at everysize: what was paid, what
 * everysize counts as refunded (COMPLETED or PENDING - a FAILED or CANCELLED
 * refund never took effect) and what is still open. Refunds made directly in
 * PayPal are in the list as well. Every comparison happens in int cents.
 */
final class RefundBalance
{
    /**
     * Cents that everysize counts as refunded.
     *
     * @param array<int, array<string, mixed>> $refunds
     */
    public static function refundedCents(array $refunds): int
    {
        $cents = 0;
        foreach ($refunds as $refund) {
            if (\in_array(strtoupper((string) ($refund['status'] ?? '')), ['COMPLETED', 'PENDING'], true)) {
                $cents += (int) round(Api\Amount::parse($refund['amount'] ?? null, 'refund amount') * 100);
            }
        }

        return $cents;
    }

    /**
     * True when the amount ("10.00") is above what is still open.
     *
     * @param array<int, array<string, mixed>> $refunds
     */
    public static function exceedsOpen(string $amount, float $paid, array $refunds): bool
    {
        $open = max(0, (int) round($paid * 100) - self::refundedCents($refunds));

        return (int) round((float) $amount * 100) > $open;
    }

    /**
     * The refundIds of a list (the state at the time an entry is created).
     *
     * @param array<int, array<string, mixed>> $refunds
     *
     * @return string[]
     */
    public static function ids(array $refunds): array
    {
        return array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['refundId'] ?? ''), $refunds), static fn (string $id): bool => $id !== ''));
    }

    /**
     * Is there an effective refund (COMPLETED or PENDING) in the list?
     *
     * @param array<int, array<string, mixed>> $refunds
     */
    public static function hasEffective(array $refunds): bool
    {
        foreach ($refunds as $refund) {
            if (\in_array(strtoupper((string) ($refund['status'] ?? '')), ['COMPLETED', 'PENDING'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Open balance in cents. */
    public static function openCents(float $paid, array $refunds): int
    {
        return max(0, (int) round($paid * 100) - self::refundedCents($refunds));
    }
}
