<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout;

use Plugin\everysize_checkout\Api\Credentials;
use Plugin\everysize_checkout\Db\Connection;

/**
 * Plugin configuration, stored in the shop database (table
 * xplugin_everysize_checkout_settings) and not in JTL's plugin settings
 * (tplugineinstellungen): the client secret must never leave the database —
 * JTL shows every plugin setting value in the admin, exports them and hands
 * them to Smarty; here the secret is write-only and the settings page keeps
 * it out of the browser entirely.
 */
class Settings implements Credentials
{
    public const CLIENT_ID = 'clientId';
    public const CLIENT_SECRET = 'clientSecret';
    /** Token for the webcron URL. */
    public const WEBCRON_TOKEN = 'webcronToken';

    /** kVersandart for imported orders; '0'/empty = first shipping method of the shop (resolved by the importer). */
    public const SHIPPING_METHOD = 'shippingMethod';

    /** Status (tbestellung.cStatus) imported orders start with; default 2 = IN_BEARBEITUNG. */
    public const IMPORT_ORDER_STATUS = 'importOrderStatus';

    /** Carrier reported to everysize with a fulfillment (one of Settings::CARRIERS or free text). */
    public const CARRIER = 'carrier';

    /** '1' (default) or '0': refund the full amount when an order is cancelled before shipping. */
    public const AUTO_REFUND_DECLINE = 'autoRefundDecline';

    /** Cancellations reported from this time on are refunded automatically (set when switched on). */
    public const AUTO_REFUND_SINCE = 'autoRefundSince';

    /** '1' (default) or '0': run import/export on cron and CLI. */
    public const SYNC_ENABLED = 'syncEnabled';

    /** '0' (default) or '1': send the JTL order confirmation mail after a successful import. */
    public const SEND_ORDER_CONFIRMATION = 'sendOrderConfirmation';

    /** JSON object: JTL carrier spelling (tversand.cLogistik / tbestellung.cLogistiker) => everysize carrier. */
    public const CARRIER_MAPPING = 'carrierMapping';

    /** Timestamp of the last sync run that did anything (throttles the fallback cron job). */
    public const LAST_SYNC = 'lastSyncAt';

    public const DEFAULT_CARRIER = 'DHL';

    /** Default import order status: JTL define BESTELLUNG_STATUS_IN_BEARBEITUNG. */
    public const DEFAULT_IMPORT_ORDER_STATUS = 2;

    /** Carriers listed in the everysize API documentation; the API accepts any value. */
    public const CARRIERS = [
        'Cargo', 'Der Courier', 'Deutsche Post', 'DHL', 'DPD', 'eparcel', 'FedEx', 'GdSK', 'GLS', 'GO!',
        'Hermes', 'iloxx', 'Midway', 'Noxxs Logistic', 'paket.ag', 'primeMail', 'TOMBA', 'UPS',
    ];

    /** @var array<int, array<string, string>> */
    private array $cache = [];

    public function __construct(private Connection $db, private int $shopId = 0)
    {
    }

    /** The connection this settings object (and the whole plugin) runs on. */
    public function getDb(): Connection
    {
        return $this->db;
    }

    public function getClientId(): string
    {
        return $this->get(self::CLIENT_ID);
    }

    public function getClientSecret(): string
    {
        return $this->get(self::CLIENT_SECRET);
    }

    public function getCarrier(): string
    {
        return $this->get(self::CARRIER, self::DEFAULT_CARRIER);
    }

    public function isAutoRefundDecline(): bool
    {
        return $this->get(self::AUTO_REFUND_DECLINE, '1') === '1';
    }

    public function getAutoRefundSince(): string
    {
        return $this->get(self::AUTO_REFUND_SINCE);
    }

    /**
     * Switches auto-refund on or off. Turning it on (from off or from an
     * empty state) anchors the since-timestamp at "now" if none is stored —
     * otherwise the first run would treat every old declined order as due.
     */
    public function setAutoRefundDecline(bool $on): void
    {
        $this->set(self::AUTO_REFUND_DECLINE, $on ? '1' : '0');
        if ($on && $this->get(self::AUTO_REFUND_SINCE) === '') {
            $this->set(self::AUTO_REFUND_SINCE, date('Y-m-d H:i:s'));
        }
    }

    public function isSyncEnabled(): bool
    {
        return $this->get(self::SYNC_ENABLED, '1') === '1';
    }

    public function shouldSendOrderConfirmation(): bool
    {
        return $this->get(self::SEND_ORDER_CONFIRMATION, '0') === '1';
    }

    /**
     * kVersandart for imported orders; 0 = the importer resolves the shop's
     * first shipping method.
     */
    public function getShippingMethodId(): int
    {
        return (int) $this->get(self::SHIPPING_METHOD, '0');
    }

    /**
     * tbestellung.cStatus imported orders start with (default 2 =
     * IN_BEARBEITUNG — the Wawi sees them as being worked on).
     */
    public function getImportOrderStatus(): int
    {
        $status = (int) $this->get(self::IMPORT_ORDER_STATUS, (string) self::DEFAULT_IMPORT_ORDER_STATUS);

        return $status !== 0 ? $status : self::DEFAULT_IMPORT_ORDER_STATUS;
    }

    /** Without credentials the plugin makes no API calls at all. */
    public function isConfigured(): bool
    {
        return $this->getClientId() !== '' && $this->getClientSecret() !== '';
    }

    /**
     * Maps a JTL carrier spelling (tversand.cLogistik) to an everysize carrier:
     * first the configured mapping, then an exact everysize carrier name,
     * otherwise '' (the caller falls back to the configured carrier).
     */
    public function mapCarrier(string $jtlCarrier): string
    {
        $jtlCarrier = trim($jtlCarrier);
        if ($jtlCarrier === '') {
            return '';
        }
        $mapping = json_decode($this->get(self::CARRIER_MAPPING), true);
        if (\is_array($mapping) && isset($mapping[$jtlCarrier]) && \is_string($mapping[$jtlCarrier]) && $mapping[$jtlCarrier] !== '') {
            return $mapping[$jtlCarrier];
        }

        return \in_array($jtlCarrier, self::CARRIERS, true) ? $jtlCarrier : '';
    }

    public function get(string $name, string $default = ''): string
    {
        $values = $this->load();
        $value = $values[$name] ?? '';

        return $value !== '' ? $value : $default;
    }

    public function set(string $name, string $value): void
    {
        // Atomic upsert: the (shop_id, name) unique key of the table makes
        // concurrent writers impossible (like JTL's ON DUPLICATE KEY UPDATE).
        $this->db->execute(
            'INSERT INTO xplugin_everysize_checkout_settings (shop_id, name, value)
             VALUES (:shopId, :name, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['shopId' => $this->shopId, 'name' => $name, 'value' => $value]
        );
        unset($this->cache[$this->shopId]);
    }

    /**
     * Removes a setting (the (shop_id, name) unique key keeps this exact).
     */
    public function remove(string $name): void
    {
        $this->db->execute(
            'DELETE FROM xplugin_everysize_checkout_settings WHERE shop_id = :shopId AND name = :name',
            ['shopId' => $this->shopId, 'name' => $name]
        );
        unset($this->cache[$this->shopId]);
    }

    /**
     * Forgets the API credentials ("Zugangsdaten entfernen" in the admin):
     * the plugin stops syncing until new ones are entered.
     */
    public function removeCredentials(): void
    {
        $this->remove(self::CLIENT_ID);
        $this->remove(self::CLIENT_SECRET);
    }

    /**
     * @return array<string, string>
     */
    private function load(): array
    {
        if (!isset($this->cache[$this->shopId])) {
            $this->cache[$this->shopId] = [];
            foreach ($this->db->getObjects(
                'SELECT name, value FROM xplugin_everysize_checkout_settings WHERE shop_id = :shopId',
                ['shopId' => $this->shopId]
            ) as $row) {
                $this->cache[$this->shopId][(string) $row->name] = trim((string) $row->value);
            }
        }

        return $this->cache[$this->shopId];
    }
}
