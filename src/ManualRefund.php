<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout;

use Plugin\everysize_checkout\Api\ApiException;
use Plugin\everysize_checkout\Api\Client;

/**
 * Every refund call of the plugin runs through here: the order page, and the automatic refund (StatusExporter).
 * A refund cannot be taken back, so a call whose outcome nobody knows is never treated like a failure.
 *
 * - R0/A1: only the answer to the refund POST itself counts. A 2xx WITH a refundId (or ALREADY_REFUNDED) is a
 *   booked refund; a 2xx without either is unclear. Only 422 with PAYPAL_REFUND_FAILED / REFUND_AMOUNT_INVALID or
 *   400 with ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS proves that no money moved. A failed token request
 *   (the POST never went out) changes nothing. Everything else is unclear.
 * - R1/A3: the one open entry of the order is reserved FIRST (OrderLinks::claimNewRefund, unique per order), then
 *   the refund list is read and its refundIds are stored in the entry. Unreadable or invalid list, stale tab,
 *   too high an amount: the entry created in this request is deleted again and nothing is sent.
 * - A4: an unknown refund (COMPLETED or PENDING, same amount, refundId neither in the stored state nor in the
 *   shop's reports) is the one the entry sent. An old foreign refund is never adopted.
 * - A5/A6/A7: the repeat and "mark as cleared" are conditional updates on the entry (token and send time); the
 *   repeat only after 150 s. A refusal of a REPEAT keeps the entry open: only the first attempt's refusal deletes.
 * - A8: success report first (refund and key), then the entry goes; a key that is already reported as booked closes
 *   the entry instead of sending.
 * - A2: the automatic run sends at most once. An open entry of the run is only checked against the list.
 */
final class ManualRefund
{
    public const TOO_HIGH = 'too_high';
    public const OTHER_AMOUNT = 'other_amount';
    public const OPEN_EXISTS = 'open_exists';
    public const DUPLICATE = 'duplicate';
    /** The same form was refused with proof before: no money moved, the key is burned - trigger it again in the current form. */
    public const KEY_REJECTED = 'key_rejected';
    public const BUSY = 'busy';
    public const STALE = 'stale';
    public const LIST_UNREADABLE = 'list_unreadable';
    public const YOUNG = 'young';
    public const NOT_OPEN = 'not_open';
    public const ORPHAN = 'orphan';
    public const UNCLEAR = 'unclear';
    /** The first attempt was refused with proof: no money moved. */
    public const REJECTED = 'rejected';
    /** A repeat was refused with proof: the entry stays open, check PayPal and mark it as cleared (A6). */
    public const REJECTED_REPEAT = 'rejected_repeat';
    public const BEFORE_POST = 'before_post';
    public const ALREADY_REFUNDED = 'already_refunded';
    public const DONE = 'done';
    public const ADOPTED = 'adopted';
    /** The key was reported as booked already: the entry is closed, nothing sent (A8). */
    public const ALREADY_REPORTED = 'already_reported';
    public const CLEARED = 'cleared';
    /** The list holds an effective refund: the automatic refund is not made. */
    public const REFUNDS_EXIST = 'refunds_exist';
    /** An open entry of the automatic run is still open after checking the list (A2: never sent again). */
    public const STILL_OPEN = 'still_open';

    public function __construct(private OrderLinks $links, private Client $client, private ?Protocol $protocol = null)
    {
    }

    /**
     * A new refund from the order page.
     *
     * @param string   $amount            "10.00" (RefundAmount::normalize())
     * @param int|null $seenRefundedCents sum of the refunds the page showed (R3); null = not checked
     *
     * @return array<string, mixed>
     */
    public function submit(int $orderId, string $everysizeOrderId, float $paid, string $amount, string $key, ?int $seenRefundedCents): array
    {
        $result = $this->result($amount);
        $cents = (int) round((float) $amount * 100);

        $open = $this->links->openRefund($orderId);
        if ($open !== null) {
            $result['open'] = $open;
            $same = $open->key !== '' && $open->amount !== OrderLinks::AMOUNT_REST && $cents === (int) round((float) $open->amount * 100);

            return ['outcome' => $same ? self::OPEN_EXISTS : self::OTHER_AMOUNT] + $result;
        }
        if ($this->hasRefundKindReport($orderId, $key) || $this->links->hasKeyReport($orderId, $key)) {
            return ['outcome' => $this->wasRejected($orderId, $key) ? self::KEY_REJECTED : self::DUPLICATE] + $result;
        }
        if (!$this->links->claimNewRefund($orderId, $key, $amount)) {
            return ['outcome' => self::BUSY] + $result;
        }
        $refunds = $this->readAndStore($orderId, $everysizeOrderId, $result);
        if ($refunds === null) {
            return ['outcome' => self::LIST_UNREADABLE] + $result;
        }
        if ($seenRefundedCents !== null && $seenRefundedCents !== RefundBalance::refundedCents($refunds)) {
            $this->links->closeRefund($orderId);

            return ['outcome' => self::STALE] + $result;
        }
        if ($paid > 0 && RefundBalance::exceedsOpen($amount, $paid, $refunds)) {
            $result['openCents'] = RefundBalance::openCents($paid, $refunds);
            $this->links->closeRefund($orderId);

            return ['outcome' => self::TOO_HIGH] + $result;
        }

        return $this->dispatch($orderId, $everysizeOrderId, $amount, $key, true, $result);
    }

    /**
     * Sends a new automatic refund (the caller has checked R4 for this very order and the setting right now).
     * Reserves first, then reads the list: an effective refund in it means the automatic refund is not made.
     *
     * @return array<string, mixed>
     */
    public function sendAutomatic(int $orderId, string $everysizeOrderId, string $key): array
    {
        $result = $this->result('');
        if (!$this->links->claimNewRefund($orderId, $key, null)) {
            return ['outcome' => self::BUSY] + $result;
        }
        $refunds = $this->readAndStore($orderId, $everysizeOrderId, $result);
        if ($refunds === null) {
            return ['outcome' => self::LIST_UNREADABLE] + $result;
        }
        if (RefundBalance::hasEffective($refunds)) {
            $this->links->closeRefund($orderId);

            return ['outcome' => self::REFUNDS_EXIST] + $result;
        }

        return $this->dispatch($orderId, $everysizeOrderId, null, $key, true, $result);
    }

    /**
     * The explicit repeat of an open entry, by a human. Reads the list first; a matching unknown refund closes the
     * entry, otherwise the entry is taken over with a conditional update and the same key is sent again.
     *
     * @return array<string, mixed>
     */
    public function retry(int $orderId, string $everysizeOrderId): array
    {
        $result = $this->result('');
        $open = $this->links->openRefund($orderId);
        if ($open === null) {
            return ['outcome' => self::NOT_OPEN] + $result;
        }
        $result['open'] = $open;
        $result['amount'] = $open->amount === OrderLinks::AMOUNT_REST ? '' : $open->amount;
        if ($open->key === '') {
            return ['outcome' => self::ORPHAN] + $result;
        }
        if ($this->alreadyReported($orderId, $open->key)) {
            $this->links->closeRefund($orderId);

            return ['outcome' => self::ALREADY_REPORTED] + $result;
        }
        $age = time() - (int) strtotime($open->sent_at);
        if ($age < OrderLinks::REFUND_MIN_AGE) {
            $result['wait'] = OrderLinks::REFUND_MIN_AGE - $age;

            return ['outcome' => self::YOUNG] + $result;
        }
        $refunds = $this->readList($everysizeOrderId);
        if ($refunds === null) {
            return ['outcome' => self::LIST_UNREADABLE] + $result;
        }
        if ($this->adoptMatch($orderId, $open, $refunds, $result)) {
            return ['outcome' => self::ADOPTED] + $result;
        }
        if (!$this->links->claimRetry($orderId, $open->token, $open->sent_at)) {
            return ['outcome' => self::BUSY] + $result;
        }

        return $this->dispatch($orderId, $everysizeOrderId, $open->amount === OrderLinks::AMOUNT_REST ? null : $open->amount, $open->key, false, $result);
    }

    /**
     * The automatic run's look at an open entry (A2): never sends. A matching unknown refund closes it as a success,
     * otherwise it stays open (and is an error in the log from 15 minutes on).
     *
     * @return array<string, mixed>
     */
    public function checkOnly(int $orderId, string $everysizeOrderId): array
    {
        $result = $this->result('');
        $open = $this->links->openRefund($orderId);
        if ($open === null) {
            return ['outcome' => self::NOT_OPEN] + $result;
        }
        $result['open'] = $open;
        if ($open->key === '') {
            return ['outcome' => self::ORPHAN] + $result;
        }
        if ($this->alreadyReported($orderId, $open->key)) {
            $this->links->closeRefund($orderId);

            return ['outcome' => self::ALREADY_REPORTED] + $result;
        }
        $refunds = $this->readList($everysizeOrderId);
        if ($refunds === null) {
            return ['outcome' => self::LIST_UNREADABLE] + $result;
        }

        return ['outcome' => $this->adoptMatch($orderId, $open, $refunds, $result) ? self::ADOPTED : self::STILL_OPEN] + $result;
    }

    /**
     * "Mark as cleared": ends the open entry without sending, only from 150 s after the last send and only if it is
     * still the same entry (conditional delete). Always with a protocol entry. The report keeps the automatic refund
     * off for this order.
     *
     * @return array<string, mixed>
     */
    public function clear(int $orderId): array
    {
        $result = $this->result('');
        $open = $this->links->openRefund($orderId);
        if ($open === null) {
            return ['outcome' => self::NOT_OPEN] + $result;
        }
        $result['open'] = $open;
        $age = time() - (int) strtotime($open->sent_at);
        if ($age < OrderLinks::REFUND_MIN_AGE) {
            $result['wait'] = OrderLinks::REFUND_MIN_AGE - $age;

            return ['outcome' => self::YOUNG] + $result;
        }
        if (!$this->links->claimClear($orderId, $open->token, date('Y-m-d H:i:s', time() - OrderLinks::REFUND_MIN_AGE))) {
            $result['wait'] = OrderLinks::REFUND_MIN_AGE;

            return ['outcome' => self::YOUNG] + $result;
        }
        $this->links->addReport($orderId, OrderLinks::KIND_REFUND, 'cleared-' . date('YmdHis'), $open->amount, OrderLinks::RESULT_CLEARED);
        $this->links->dropEntryData($orderId);
        if ($this->protocol !== null) {
            try {
                $this->protocol->add(
                    'Refund marked as cleared (order ' . $orderId . ')',
                    sprintf('Open refund of %s (key %s, started %s) was ended by the merchant without sending anything.', $open->amount, $open->key, $open->created_at),
                    true
                );
            } catch (\Throwable) {
                // the report above is the record; the protocol is best effort
            }
        }

        return ['outcome' => self::CLEARED] + $result;
    }

    /**
     * Reads the list and stores its refundIds in the entry (A3). Unreadable, invalid or not storable: the entry of
     * this request is deleted again and null is returned.
     *
     * @param array<string, mixed> $result
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function readAndStore(int $orderId, string $everysizeOrderId, array &$result): ?array
    {
        $refunds = $this->readList($everysizeOrderId);
        if ($refunds !== null) {
            try {
                $this->links->saveKnownRefunds($orderId, RefundBalance::ids($refunds));
            } catch (\Throwable) {
                $refunds = null;
            }
        }
        if ($refunds === null) {
            $this->links->closeRefund($orderId);
        }

        return $refunds;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function readList(string $everysizeOrderId): ?array
    {
        try {
            return $this->client->getRefunds($everysizeOrderId);
        } catch (ApiException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed>             $result
     * @param array<int, array<string, mixed>> $refunds
     */
    private function adoptMatch(int $orderId, object $open, array $refunds, array &$result): bool
    {
        $match = $this->unknownMatch($orderId, $open->amount, $refunds);
        if ($match === null) {
            return false;
        }
        $this->recordSuccess($orderId, $open->key, (string) $match['refundId'], $open->amount === OrderLinks::AMOUNT_REST ? (string) ($match['amount'] ?? '') : $open->amount, $this->modeOf($open->key));
        $result['refundId'] = (string) $match['refundId'];

        return true;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function dispatch(int $orderId, string $everysizeOrderId, ?string $amount, string $key, bool $isNew, array $result): array
    {
        $mode = $this->modeOf($key);
        try {
            $refund = $this->client->refund($everysizeOrderId, $amount, $key);
        } catch (ApiException $e) {
            $result['message'] = $e->getMessage();
            $result['exception'] = $e;
            if ($e->isBeforePost()) {
                // Nothing went out. A new entry of this request is dropped, an older one stays open.
                if ($isNew) {
                    $this->links->closeRefund($orderId);
                }

                return ['outcome' => self::BEFORE_POST] + $result;
            }
            if ($e->isSafelyRejected()) {
                if (!$isNew) {
                    // A6: only the first attempt's refusal deletes the entry. A repeat that is refused leaves it open.
                    return ['outcome' => self::REJECTED_REPEAT] + $result;
                }
                if ($mode === 'auto') {
                    $this->links->addReport($orderId, OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, mb_substr($e->getReason() !== '' ? $e->getReason() : 'HTTP ' . $e->getStatus(), 0, 32), OrderLinks::RESULT_SKIPPED);
                } else {
                    // Burn the key: the same form (back button, reload of the POST) must not reach the POST again.
                    $this->links->addReport($orderId, OrderLinks::KIND_REFUND, $key, $amount ?? '', OrderLinks::RESULT_REJECTED);
                }
                $this->links->closeRefund($orderId);

                return ['outcome' => self::REJECTED] + $result;
            }

            return ['outcome' => self::UNCLEAR] + $result;
        }

        $result['status'] = strtoupper((string) ($refund['status'] ?? ''));
        $refundId = is_string($refund['refundId'] ?? null) ? $refund['refundId'] : '';
        if ($refundId === '' && $result['status'] !== 'ALREADY_REFUNDED') {
            // A1: a 2xx without a refundId is no proof of a booked refund.
            $result['message'] = 'HTTP 2xx without refundId';

            return ['outcome' => self::UNCLEAR] + $result;
        }
        try {
            if ($result['status'] === 'ALREADY_REFUNDED') {
                $this->recordKnown($orderId, $key, $mode, $result['status'], $amount);
                $this->links->closeRefund($orderId);
                $outcome = self::ALREADY_REFUNDED;
            } else {
                $this->recordSuccess($orderId, $key, $refundId, $amount ?? '', $mode, $result['status']);
                $outcome = self::DONE;
            }
        } catch (\Throwable $e) {
            // After a 2xx nothing says "failed": the refund IS booked, only the shop's record is incomplete (A8).
            $result['warning'] = 'The refund is booked at everysize (' . ($refundId !== '' ? $refundId : $result['status']) . '), but recording it in the shop failed: ' . $e->getMessage();
            $outcome = $result['status'] === 'ALREADY_REFUNDED' ? self::ALREADY_REFUNDED : self::DONE;
        }
        $result['refundId'] = $refundId;

        return ['outcome' => $outcome] + $result;
    }

    /** The success report (refund, then key) first, then the open entry goes. */
    private function recordSuccess(int $orderId, string $key, string $refundId, string $amount, string $mode, string $status = ''): void
    {
        if ($mode === 'auto') {
            $this->links->addReport($orderId, OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, $refundId !== '' ? $refundId : $status, OrderLinks::RESULT_SENT);
        } else {
            if (!$this->hasRefundKindReport($orderId, $refundId)) {
                $this->links->addReport($orderId, OrderLinks::KIND_REFUND, $refundId, $amount, OrderLinks::RESULT_SENT);
            }
            if (!$this->links->hasKeyReport($orderId, $key)) {
                $this->links->addReport($orderId, OrderLinks::KIND_REFUND_KEY, $key, $refundId, OrderLinks::RESULT_SENT);
            }
        }
        $this->links->closeRefund($orderId);
    }

    private function recordKnown(int $orderId, string $key, string $mode, string $status, ?string $amount): void
    {
        if ($mode === 'auto') {
            $this->links->addReport($orderId, OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, $status, OrderLinks::RESULT_KNOWN);
        } else {
            $this->links->addReport($orderId, OrderLinks::KIND_REFUND, $key, $amount ?? '', OrderLinks::RESULT_KNOWN);
            $this->links->addReport($orderId, OrderLinks::KIND_REFUND_KEY, $key, $status, OrderLinks::RESULT_SENT);
        }
    }

    /**
     * A8: has the refund of this key been reported as booked already (a success report whose entry could not be
     * deleted)? Then the entry is closed, never sent again.
     */
    private function alreadyReported(int $orderId, string $key): bool
    {
        if ($this->modeOf($key) === 'auto') {
            foreach ($this->links->getReports($orderId) as $report) {
                if ($report->kind === OrderLinks::KIND_REFUND && $report->sku === OrderLinks::AUTO_REFUND && \in_array($report->result, [OrderLinks::RESULT_SENT, OrderLinks::RESULT_KNOWN], true)) {
                    return true;
                }
            }

            return false;
        }

        return $this->links->hasKeyReport($orderId, $key);
    }

    /**
     * A4: a refund of the list that matches the open entry and that is NOT among the refundIds stored when the entry
     * was created and NOT in the shop's refund reports: COMPLETED or PENDING, same amount - for "rest" any amount.
     *
     * @param array<int, array<string, mixed>> $refunds
     *
     * @return array<string, mixed>|null
     */
    private function unknownMatch(int $orderId, string $amount, array $refunds): ?array
    {
        $known = $this->links->knownRefundIds($orderId);
        foreach ($this->links->getReports($orderId) as $report) {
            if ($report->kind === OrderLinks::KIND_REFUND) {
                $known[] = (string) $report->sku;
                $known[] = (string) $report->reason;
            }
        }
        foreach ($refunds as $refund) {
            if (!\in_array(strtoupper((string) ($refund['status'] ?? '')), ['COMPLETED', 'PENDING'], true)) {
                continue;
            }
            $id = (string) ($refund['refundId'] ?? '');
            if ($id === '' || \in_array($id, $known, true)) {
                continue;
            }
            if ($amount === OrderLinks::AMOUNT_REST
                || (int) round(Api\Amount::parse($refund['amount'] ?? null, 'refund amount') * 100) === (int) round((float) $amount * 100)) {
                return $refund;
            }
        }

        return null;
    }

    private function modeOf(string $key): string
    {
        return str_starts_with($key, OrderLinks::AUTO_REFUND . '-') ? 'auto' : 'manual';
    }

    /** Was this form key burned by a proven refusal (result rejected)? */
    private function wasRejected(int $orderId, string $key): bool
    {
        foreach ($this->links->getReports($orderId) as $report) {
            if ($report->kind === OrderLinks::KIND_REFUND && $report->sku === $key && $report->result === OrderLinks::RESULT_REJECTED) {
                return true;
            }
        }

        return false;
    }

    /** Only refund reports count (never the entry's own row): this key or this refundId was reported. */
    private function hasRefundKindReport(int $orderId, string $reference): bool
    {
        foreach ($this->links->getReports($orderId) as $report) {
            if ($report->kind === OrderLinks::KIND_REFUND && $report->sku === $reference) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $amount): array
    {
        return ['amount' => $amount, 'openCents' => 0, 'message' => '', 'warning' => '', 'status' => '', 'refundId' => '', 'wait' => 0, 'open' => null, 'exception' => null];
    }
}
