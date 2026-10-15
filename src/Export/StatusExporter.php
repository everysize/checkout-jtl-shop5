<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Export;

use Plugin\everysize_checkout\Api\ApiException;
use Plugin\everysize_checkout\Api\Client;
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
 * through everysize afterwards, unless switched off: nothing was shipped, so
 * the customer is owed everything. Returns are left to the merchant (goods
 * first). The idempotency key is fixed per order, so a repeated run never
 * refunds twice; transport errors are retried, a refusal is recorded once.
 */
class StatusExporter
{
    /** A tracking code entered later is reported up to this many days after shipping. */
    public const TRACKING_DAYS = 14;

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

        foreach ($this->links->getPendingRevocations() as $row) {
            ++$result['pending'];
            $isReturn = $this->links->hasSentFulfillment((int) $row->ORDERID) || (int) $row->shipped === 1;
            $reason = $isReturn ? Client::REASON_RETOUR : Client::REASON_MERCHANT_DECLINE;
            try {
                if ($this->isRevokedAtEverysize($row->everysize_order_id)) {
                    $this->links->addReport((int) $row->ORDERID, OrderLinks::KIND_REVOCATION, (string) $row->sku, $reason, OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    continue;
                }

                $this->client->sendRevocation($row->everysize_order_id, (string) $row->sku, $reason);
                $this->links->addReport((int) $row->ORDERID, OrderLinks::KIND_REVOCATION, (string) $row->sku, $reason, OrderLinks::RESULT_SENT);
                ++$result[$isReturn ? 'returns' : 'revocations'];
            } catch (ApiException $e) {
                $result['errors'][] = sprintf('Order %s (%s), %s: %s', $row->cBestellNr, $row->everysize_order_id, $row->sku, $e->getMessage());
            }
        }

        // From when the setting was switched on, and no older than TRACKING_DAYS (a lasting outage is not retried forever).
        $since = $this->settings->getAutoRefundSince();
        $since = $since !== '' ? max($since, date('Y-m-d H:i:s', time() - self::TRACKING_DAYS * 86400)) : '';
        $declined = $this->settings->isAutoRefundDecline() && $since !== '' ? $this->links->getPendingAutoRefunds($since) : [];
        foreach ($declined as $row) {
            ++$result['pending'];
            try {
                $refund = $this->client->refund($row->everysize_order_id, null, OrderLinks::AUTO_REFUND . '-' . $row->everysize_order_id);
                $status = strtoupper((string) ($refund['status'] ?? ''));
                if ($status === 'ALREADY_REFUNDED') {
                    $this->links->addReport((int) $row->kBestellung, OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, $status, OrderLinks::RESULT_KNOWN);
                    continue;
                }
                $this->links->addReport((int) $row->kBestellung, OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, (string) ($refund['refundId'] ?? $status), OrderLinks::RESULT_SENT);
                ++$result['autoRefunds'];
            } catch (ApiException $e) {
                // No answer or a server error: try again next run. A refusal (not refundable, PayPal said no) stays as it is.
                $retry = $e->getStatus() === 0 || ($e->getStatus() >= 500 && $e->getReason() !== 'PAYPAL_REFUND_FAILED');
                if (!$retry) {
                    $this->links->addReport((int) $row->kBestellung, OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, mb_substr($e->getReason() !== '' ? $e->getReason() : 'HTTP ' . $e->getStatus(), 0, 32), OrderLinks::RESULT_SKIPPED);
                }
                $result['errors'][] = sprintf(
                    'Order %s (%s): automatic refund after cancellation failed%s: %s',
                    $row->cBestellNr,
                    $row->everysize_order_id,
                    $retry ? ' (retried next run)' : ', please refund on the order page',
                    $e->getMessage()
                );
            }
        }

        return $result;
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
