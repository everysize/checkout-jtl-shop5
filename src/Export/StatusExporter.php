<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Export;

use Plugin\everysize_checkout\Api\ApiException;
use Plugin\everysize_checkout\Api\Client;
use Plugin\everysize_checkout\ManualRefund;
use Plugin\everysize_checkout\OrderLinks;
use Plugin\everysize_checkout\Settings;

/**
 * Reports shipping, cancellations and returns from JTL to everysize.
 *
 * - Order shipped (status versandt/teilversandt or a delivery note with
 *   shipping — the way JTL-Wawi feeds the shop) → fulfillment with the
 *   order's tracking code and the carrier (manual entry, else the mapped
 *   Wawi carrier, else the configured one).
 * - Tracking code entered or corrected after that, within TRACKING_DAYS →
 *   the fulfillment once more with the new code. everysize takes a repeated
 *   fulfillment as an update of carrier and code; the customer gets no mail.
 * - Order cancelled in JTL (cStatus = -1) before the fulfillment was reported →
 *   revocation MERCHANT_DECLINE; cancelled after it → RETOUR (JTL has no
 *   return status; merchants book returns by cancelling the order, which also
 *   puts the stock back).
 *
 * Before reporting, the order is fetched from everysize: if it was revoked
 * there in the meantime (PayPal refund, partner backoffice), nothing is sent.
 * A fulfillment would set a revoked order back to COMPLETED, a revocation
 * would overwrite everysize's own reason. A late tracking code goes out only
 * while the order is COMPLETED at everysize, and only if everysize does not
 * have that code yet (orders shipped with 1.0.0 have no remembered code).
 *
 * A cancellation before shipping (MERCHANT_DECLINE) is refunded in full
 * through everysize afterwards, unless switched off: nothing was shipped (no
 * status, shipping date, delivery note or tracking code, also none entered by
 * hand), so the customer is owed everything. Before that the refund list is
 * read: any refund COMPLETED or PENDING (also one made directly in PayPal)
 * stops it and is recorded; a list that cannot be read skips the run. Returns
 * are left to the merchant (goods first). The idempotency key is fixed per
 * order, so a repeated run never refunds twice; transport errors are retried
 * with the same key, a refusal is recorded once.
 */
class StatusExporter
{
    /** A tracking code entered later is reported up to this many days after shipping. */
    public const TRACKING_DAYS = 14;

    /** Reason of the automatic-refund report when everysize already lists a refund. */
    public const REFUNDS_EXIST = 'REFUNDS_EXIST';

    /** Reason of the automatic-refund report that marks a cancellation the run excluded (A13). */
    public const BLOCKED = 'BLOCKED';

    private Client $client;

    private Settings $settings;

    private OrderLinks $links;

    public function __construct(Client $client, Settings $settings, OrderLinks $links)
    {
        $this->client = $client;
        $this->settings = $settings;
        $this->links = $links;
    }

    /**
     * @return array{fulfillments: int, trackingCodes: int, revocations: int, returns: int, autoRefunds: int, skipped: int, pending: int, errors: string[]}
     */
    public function export(): array
    {
        $result = ['fulfillments' => 0, 'trackingCodes' => 0, 'revocations' => 0, 'returns' => 0, 'autoRefunds' => 0, 'skipped' => 0, 'pending' => 0, 'errors' => []];

        // Orders whose shipping report failed (now or earlier) and is still to be made up: their revocation waits,
        // it would go out as MERCHANT_DECLINE although the goods are on their way.
        $failedFulfillments = [];

        foreach ($this->links->getPendingFulfillments() as $row) {
            ++$result['pending'];
            try {
                if ($this->isRevokedAtEverysize($row->everysize_order_id)) {
                    $this->links->addReport((int) $row->kBestellung, OrderLinks::KIND_FULFILLMENT, '', '', OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    $result['errors'][] = sprintf(
                        'Order %s (%s) is revoked at everysize, shipping not reported',
                        $row->cBestellNr,
                        $row->everysize_order_id
                    );
                    continue;
                }

                $trackingCode = trim((string) $row->tracking_code);
                $this->client->sendFulfillment($row->everysize_order_id, $this->carrierOf($row), $trackingCode);
                $this->links->addReport((int) $row->kBestellung, OrderLinks::KIND_FULFILLMENT, '', '', OrderLinks::RESULT_SENT);
                if ($this->sendsTracking($trackingCode, $row)) {
                    $this->links->addReport((int) $row->kBestellung, OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_SENT);
                }
                ++$result['fulfillments'];
            } catch (ApiException $e) {
                // The order was shipped, only everysize does not know yet: remember that (shipping proof for
                // revocation and automatic refund) and report it again in the next run.
                $this->links->markFulfillmentFailed((int) $row->kBestellung);
                $failedFulfillments[(int) $row->kBestellung] = true;
                $result['errors'][] = sprintf('Order %s (%s): %s', $row->cBestellNr, $row->everysize_order_id, $e->getMessage());
            }
        }

        // Without a carrier the API takes no tracking code (Client::sendFulfillment): nothing to add later.
        $since = date('Y-m-d H:i:s', time() - self::TRACKING_DAYS * 86400);
        $late = array_values(array_filter(
            $this->links->getPendingTrackingCodes($since),
            fn (object $row): bool => $this->carrierOf($row) !== ''
        ));
        foreach ($late as $row) {
            ++$result['pending'];
            $trackingCode = $row->tracking_code;
            try {
                $order = $this->client->getOrder($row->everysize_order_id);
                $status = strtoupper((string) ($order['status'] ?? ''));
                if ($status !== Client::STATUS_COMPLETED) {
                    $this->links->addReport((int) $row->kBestellung, OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    $result['errors'][] = sprintf(
                        'Order %s (%s) is %s at everysize, tracking code %s not reported',
                        $row->cBestellNr,
                        $row->everysize_order_id,
                        $status !== '' ? $status : 'unknown',
                        $trackingCode
                    );
                    continue;
                }

                if (self::trackingCodeOf($order) === $trackingCode) {
                    $this->links->addReport((int) $row->kBestellung, OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_KNOWN);
                    continue;
                }

                $this->client->sendFulfillment($row->everysize_order_id, $this->carrierOf($row), $trackingCode);
                $this->links->addReport((int) $row->kBestellung, OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_SENT);
                ++$result['trackingCodes'];
            } catch (ApiException $e) {
                $result['errors'][] = sprintf('Order %s (%s), tracking code %s: %s', $row->cBestellNr, $row->everysize_order_id, $trackingCode, $e->getMessage());
            }
        }

        $revokedNoted = [];
        foreach ($this->links->getPendingRevocations() as $row) {
            $orderId = (int) $row->ORDERID;
            if (isset($failedFulfillments[$orderId]) || ($this->links->hasFailedFulfillment($orderId) && !$this->links->hasFulfillmentReport($orderId))) {
                $result['errors'][] = sprintf('Order %s (%s): shipping report not made yet, revocation of %s waits (next run)', $row->cBestellNr, $row->everysize_order_id, $row->sku);
                continue;
            }
            ++$result['pending'];
            $isReturn = $this->links->hasSentFulfillment((int) $row->ORDERID) || (int) $row->shipped === 1;
            $reason = $isReturn ? Client::REASON_RETOUR : Client::REASON_MERCHANT_DECLINE;
            try {
                if ($this->isRevokedAtEverysize($row->everysize_order_id)) {
                    $this->links->addReport((int) $row->ORDERID, OrderLinks::KIND_REVOCATION, (string) $row->sku, $reason, OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    // Revoked at everysize already (the partner): the revocation is not reported, so there is no
                    // automatic refund either (both queries need a sent revocation). Not silently: one note (the report
                    // row writes it only the first time; at most one message per order and run).
                    if (!$isReturn && !isset($revokedNoted[$orderId])) {
                        $revokedNoted[$orderId] = true;
                        $result['errors'][] = sprintf(
                            'Order %s (%s): already revoked at everysize, cancellation not reported and NOT refunded automatically - please check and refund on the order page if it is due',
                            $row->cBestellNr,
                            $row->everysize_order_id
                        );
                    }
                    continue;
                }

                $this->client->sendRevocation($row->everysize_order_id, (string) $row->sku, $reason);
                $this->links->addReport((int) $row->ORDERID, OrderLinks::KIND_REVOCATION, (string) $row->sku, $reason, OrderLinks::RESULT_SENT);
                ++$result[$isReturn ? 'returns' : 'revocations'];
            } catch (ApiException $e) {
                $result['errors'][] = sprintf('Order %s (%s), %s: %s', $row->cBestellNr, $row->everysize_order_id, $row->sku, $e->getMessage());
            }
        }

        $this->autoRefunds($result);

        return $result;
    }

    /**
     * Automatic refund after a cancellation before shipping (R4, A2). The run sends AT MOST ONCE per order: the
     * entry is reserved, the list is read and its refundIds stored, then one POST. An open entry is afterwards only
     * checked against the list (a match closes it as a success); it is never sent again by a run, only by a human.
     * Before the one send the setting and the conditions R4 asks for are checked again for exactly that order.
     * Each order on its own: nothing aborts the run.
     *
     * @param array<string, mixed> $result
     */
    private function autoRefunds(array &$result): void
    {
        $refunds = new ManualRefund($this->links, $this->client);

        // Open entries: checked against the list only; every one older than 15 minutes (since it was CREATED) is an
        // error in each run.
        foreach ($this->links->getOpenRefundOrders() as $row) {
            $orderId = (int) $row->kBestellung;
            try {
                $open = $this->links->openRefund($orderId);
                if ($open === null) {
                    continue;
                }
                if (time() - (int) strtotime($open->created_at) > 900) {
                    $result['errors'][] = sprintf(
                        'Order %s (%s): refund of %s still unclear since %s, please check PayPal and the order page',
                        $row->cBestellNr,
                        $row->everysize_order_id,
                        $open->amount !== '' ? $open->amount : '?',
                        $open->created_at
                    );
                }
                if (str_starts_with($open->key, OrderLinks::AUTO_REFUND . '-')) {
                    ++$result['pending'];
                    $this->noteAutoOutcome($refunds->checkOnly($orderId, (string) $row->everysize_order_id), $row, $result);
                }
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('Order %s (%s): %s', $row->cBestellNr ?? '?', $row->everysize_order_id ?? '?', $e->getMessage());
            }
        }

        // From when the setting was switched on, and no older than TRACKING_DAYS (a lasting outage is not retried forever).
        $since = $this->settings->getAutoRefundSince();
        $since = $since !== '' ? max($since, date('Y-m-d H:i:s', time() - self::TRACKING_DAYS * 86400)) : '';
        if (!$this->settings->isAutoRefundDecline() || $since === '') {
            return;
        }
        $declined = $this->links->getPendingAutoRefunds($since);
        foreach ($declined as $row) {
            ++$result['pending'];
            $orderId = (int) $row->kBestellung;
            try {
                if ($this->links->openRefund($orderId) !== null) {
                    continue; // an open entry blocks everything else
                }
                // The setting and R4 for exactly this order, right before the one send.
                if (!$this->stillEligible($orderId, $since) || !$this->settings->isAutoRefundDecline()) {
                    continue;
                }
                $outcome = $refunds->sendAutomatic($orderId, (string) $row->everysize_order_id, OrderLinks::AUTO_REFUND . '-' . $row->everysize_order_id);
                if ($outcome['outcome'] === ManualRefund::REFUNDS_EXIST) {
                    $this->links->addReport($orderId, OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, self::REFUNDS_EXIST, OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    $result['errors'][] = sprintf(
                        'Order %s (%s): automatic refund not made, everysize already lists a refund (PayPal), please check on the order page',
                        $row->cBestellNr,
                        $row->everysize_order_id
                    );
                    continue;
                }
                $this->noteAutoOutcome($outcome, $row, $result);
            } catch (\Throwable $e) {
                $result['errors'][] = sprintf('Order %s (%s): %s', $row->cBestellNr ?? '?', $row->everysize_order_id ?? '?', $e->getMessage());
            }
        }

        // A13: a cancellation the query excluded (shipped, tracking code, delivery note, credit ...) must not wait
        // silently: say so once (the marker report keeps the automatic refund off and the note from repeating).
        try {
            $candidateIds = array_map(static fn (object $r): int => (int) $r->kBestellung, $declined);
            foreach ($this->links->getCancelledDeclines($since) as $row) {
                if (\in_array((int) $row->kBestellung, $candidateIds, true)) {
                    continue;
                }
                $this->links->addReport((int) $row->kBestellung, OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, self::BLOCKED, OrderLinks::RESULT_SKIPPED);
                $result['errors'][] = sprintf(
                    'Order %s (%s): cancelled before shipping but NOT refunded automatically (shipped, tracking code, delivery note or an earlier refund was found) - please check and refund on the order page if it is due',
                    $row->cBestellNr,
                    $row->everysize_order_id
                );
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'blocked cancellations check failed: ' . $e->getMessage();
        }
    }

    /** R4 again for exactly this order, right before the send. */
    private function stillEligible(int $orderId, string $since): bool
    {
        foreach ($this->links->getPendingAutoRefunds($since) as $row) {
            if ((int) $row->kBestellung === $orderId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $outcome
     * @param array<string, mixed> $result
     */
    private function noteAutoOutcome(array $outcome, object $row, array &$result): void
    {
        $label = sprintf('Order %s (%s)', $row->cBestellNr, $row->everysize_order_id);
        switch ($outcome['outcome']) {
            case ManualRefund::DONE:
            case ManualRefund::ADOPTED:
                ++$result['autoRefunds'];
                if (($outcome['warning'] ?? '') !== '') {
                    $result['errors'][] = $label . ': ' . $outcome['warning'];
                }
                break;
            case ManualRefund::REJECTED:
                $result['errors'][] = $label . ': automatic refund after cancellation rejected, please refund on the order page: ' . $outcome['message'];
                break;
            case ManualRefund::UNCLEAR:
                $result['errors'][] = $label . ': automatic refund result unclear, the money may have been paid out - checked again from 150 s on after reading the PayPal list: ' . $outcome['message'];
                break;
            case ManualRefund::BEFORE_POST:
                $result['errors'][] = $label . ': automatic refund not sent (token request failed), next run: ' . $outcome['message'];
                break;
            case ManualRefund::LIST_UNREADABLE:
                $result['errors'][] = $label . ': refunds not readable, nothing sent (next run)';
                break;
            default:
                break;
        }
    }

    /**
     * A refund that took effect or is on its way (COMPLETED or PENDING) - a FAILED or CANCELLED one never did.
     *
     * @param array<int, array<string, mixed>> $list
     */
    private function hasEffective(array $list): bool
    {
        foreach ($list as $refund) {
            if (\in_array(strtoupper((string) ($refund['status'] ?? '')), ['COMPLETED', 'PENDING'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isRevokedAtEverysize(string $everysizeOrderId): bool
    {
        $order = $this->client->getOrder($everysizeOrderId);

        return strtoupper((string) ($order['status'] ?? '')) === Client::STATUS_REVOKED;
    }

    /**
     * The tracking code everysize has for an order: fulfillment.tracking is
     * {code, carrier}, or an empty list without one.
     *
     * @param array<string, mixed> $order
     */
    private static function trackingCodeOf(array $order): string
    {
        $tracking = $order['fulfillment']['tracking'] ?? null;
        if (\is_array($tracking) && isset($tracking[0]) && \is_array($tracking[0])) {
            $tracking = $tracking[0];
        }

        return \is_array($tracking) && isset($tracking['code']) ? trim((string) $tracking['code']) : '';
    }

    /** The API takes a tracking code only together with a carrier (Client::sendFulfillment). */
    private function sendsTracking(string $trackingCode, object $row): bool
    {
        return $trackingCode !== '' && $this->carrierOf($row) !== '';
    }

    /**
     * Carrier for an order row: the manual entry of the plugin's order page
     * wins, then the Wawi carrier via the settings mapping, then the
     * configured default.
     *
     * @param object{manual_carrier: string, wawi_carrier: string} $row
     */
    private function carrierOf(object $row): string
    {
        $manual = trim((string) $row->manual_carrier);
        if ($manual !== '') {
            return $manual;
        }
        $wawi = trim((string) $row->wawi_carrier);
        if ($wawi !== '') {
            $mapped = $this->settings->mapCarrier($wawi);
            if ($mapped !== '') {
                return $mapped;
            }
        }

        return $this->settings->getCarrier();
    }
}
