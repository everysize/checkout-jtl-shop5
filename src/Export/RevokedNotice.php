<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Export;

use JTL\Shop;
use Plugin\everysize_checkout\Api\Amount;
use Plugin\everysize_checkout\Api\ApiException;
use Plugin\everysize_checkout\Api\Client;
use Plugin\everysize_checkout\OrderLinks;
use Plugin\everysize_checkout\Protocol;
use Plugin\everysize_checkout\Settings;

/**
 * Cancellation check: an order that was imported and is cancelled at everysize afterwards gets a hint for the
 * merchant (on the plugin's order page, marked in its list, entry in the protocol). The Wawi carries the orders on,
 * so nothing in the shop's order or in the Wawi data is touched: no status, no stock, no refund, no comment in the
 * order, no e-mail, and nothing is reported to everysize. The hint is the marker row (kind es-revoked, unique per
 * order), so it appears exactly once. It stays only while there is something to warn about: once the order is
 * cancelled or refunded in full it is not shown any more (a partial refund does not close the order).
 *
 * Runs after import and export and must never disturb them: run() does not throw, it only counts.
 *
 * Errors of this step reach the protocol at most once per day and kind of error (list not readable / an order
 * failed: all order errors are one kind); everything above goes to the technical log of the shop only. Without that
 * limit an outage of the API would fill the protocol every run.
 */
class RevokedNotice
{
    /** Orders created within this many days are checked. */
    public const WINDOW_DAYS = 30;

    public const OPEN = 'OPEN';
    public const SHIPPED = 'SHIPPED';

    private const TEXTS = [
        self::OPEN => [
            'de' => 'Bestellung %s wurde bei everysize storniert. Bitte nicht versenden und im Shop stornieren.',
            'en' => 'Order %s was cancelled at everysize. Please do not ship it and cancel it in the shop.',
        ],
        self::SHIPPED => [
            'de' => 'Bestellung %s wurde bei everysize storniert, ist hier aber schon als versendet markiert. Bitte prüfen.',
            'en' => 'Order %s was cancelled at everysize but is already marked as shipped here. Please check.',
        ],
    ];

    /** Setting names (table xplugin_everysize_checkout_settings) that remember the day of the last warning per kind. */
    private const THROTTLE_LIST = 'revokedWarnList';
    private const THROTTLE_ORDER = 'revokedWarnOrder';

    private string $today = '';

    public function __construct(
        private Client $client,
        private OrderLinks $links,
        private Protocol $protocol,
        private ?Settings $settings = null
    ) {
    }

    /** The hint in German or English (anything else: English). Only the order number, no customer data. */
    public static function text(string $kind, string $iso, string $orderNumber): string
    {
        $texts = self::TEXTS[$kind] ?? self::TEXTS[self::OPEN];

        return sprintf($texts[strtolower($iso) === 'de' ? 'de' : 'en'], $orderNumber);
    }

    /**
     * @return array{revoked: int, noted: int, closed: int, skipped: int, warnings: int}
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $result = ['revoked' => 0, 'noted' => 0, 'closed' => 0, 'skipped' => 0, 'warnings' => 0];

        try {
            $now ??= new \DateTimeImmutable('now');
            $this->today = $now->format('Y-m-d');
            $orders = $this->client->getRevokedOrders($now->modify('-' . self::WINDOW_DAYS . ' days'), $now);
        } catch (\Throwable $e) {
            $this->warn($result, self::THROTTLE_LIST, 'everysize: cancellation check skipped', $e->getMessage());

            return $result;
        }

        $result['revoked'] = \count($orders);
        foreach ($orders as $order) {
            try {
                $this->handle($order, $result);
            } catch (\Throwable $e) {
                $this->warn($result, self::THROTTLE_ORDER, sprintf('everysize: cancellation check failed for order %s', (string) ($order['everysizeOrderId'] ?? '?')), $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, int>   $result
     */
    private function handle(array $order, array &$result): void
    {
        $everysizeOrderId = (string) $order['everysizeOrderId'];
        $orderId = $this->links->findOrderId($everysizeOrderId);
        if ($orderId === null) {
            ++$result['skipped']; // not linked to a shop order

            return;
        }
        if ($this->links->hasReport($orderId, OrderLinks::KIND_REVOKED_AT_EVERYSIZE, '')) {
            ++$result['skipped']; // done already

            return;
        }

        // What the customer paid, in cents (grossPrice of the everysize order); unreadable: the total of the shop order counts.
        try {
            $paidCents = Amount::cents($order['grossPrice'] ?? null, 'grossPrice');
        } catch (ApiException) {
            $paidCents = null;
        }
        $info = $this->links->getRevokedCheckInfo($orderId, $paidCents);
        if ($info === null) {
            ++$result['skipped']; // the order is gone

            return;
        }

        // Cancelled or refunded in full already (also: this plugin reported the cancellation itself): marker only.
        if ($info['closed']) {
            $this->links->addReport($orderId, OrderLinks::KIND_REVOKED_AT_EVERYSIZE, '', '', OrderLinks::RESULT_SKIPPED);
            ++$result['closed'];

            return;
        }

        $kind = $info['shipped'] ? self::SHIPPED : self::OPEN;
        // The marker is the hint (order page, list): a second run never writes a second one. If writing it fails,
        // nothing is hinted and the next run tries again.
        $this->links->addReport($orderId, OrderLinks::KIND_REVOKED_AT_EVERYSIZE, '', $kind, OrderLinks::RESULT_NOTED);
        try {
            $this->protocol->warn(self::text($kind, $info['iso'], $info['number']));
        } catch (\Throwable $e) {
            // the protocol line is an addition
        }
        ++$result['noted'];
    }

    /**
     * A warning in the protocol, never an error: the check does not count as a failure of the run. At most one
     * warning per day and kind reaches the protocol, the rest goes to the technical log. If the limit itself
     * fails, nothing is written to the protocol.
     *
     * @param array<string, int> $result
     */
    private function warn(array &$result, string $kind, string $title, string $message): void
    {
        ++$result['warnings'];
        try {
            if ($this->mayWriteToProtocol($kind)) {
                $this->protocol->warn($title, $message);

                return;
            }
        } catch (\Throwable $e) {
            // the protocol and the limit must never disturb
        }
        try {
            $this->log($title, $message);
        } catch (\Throwable $e) {
            // dropped
        }
    }

    /** true once per day and kind (the day is remembered in the settings table); false if the limit cannot be read or written. */
    private function mayWriteToProtocol(string $kind): bool
    {
        if ($this->settings === null || $this->today === '') {
            return false;
        }
        try {
            if ($this->settings->get($kind) === $this->today) {
                return false;
            }
            $this->settings->set($kind, $this->today);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /** The technical log of the shop (JTL log service). */
    protected function log(string $title, string $message): void
    {
        Shop::Container()->getLogService()->warning($title . ($message !== '' ? ': ' . $message : ''));
    }
}
