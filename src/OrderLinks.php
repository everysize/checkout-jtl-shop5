<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout;

use Plugin\everysize_checkout\Db\Connection;

/**
 * Links JTL orders to everysize orders and records what was reported back.
 *
 * Every report is stored with a unique key (order, kind, SKU), so fulfillment
 * and revocation go out exactly once per order item — editing or re-saving an
 * order in the admin does not touch these rows. A tracking code is stored as
 * kind "tracking" with the code in the SKU column: each code goes out once,
 * so a code entered or corrected after shipping is reported once more.
 *
 * Shipping in JTL is the status versandt/teilversandt, a delivery note with
 * shipping or the JTL shipping date (tbestellung.dVersandDatum — the way
 * JTL-Wawi per dbeS feeds the shop); the tracking code
 * comes from the plugin's order page (_tracking) or from tversand.cIdentCode.
 */
class OrderLinks
{
    public const KIND_FULFILLMENT = 'fulfillment';
    public const KIND_REVOCATION = 'revocation';
    public const KIND_TRACKING = 'tracking';
    /** A refund through everysize from the order page; SKU = PayPal refund ID, REASON = amount. */
    public const KIND_REFUND = 'refund';
    /** SKU of the automatic full refund after a cancellation before shipping (REASON = PayPal refund ID or why not). */
    public const AUTO_REFUND = 'auto-decline';

    /** Sent to everysize. */
    public const RESULT_SENT = 'sent';
    /** Not sent: the order was already revoked at everysize. */
    public const RESULT_SKIPPED = 'skipped';
    /** Not sent: everysize already had it (a tracking code reported before the plugin remembered codes). */
    public const RESULT_KNOWN = 'known';

    /** tbestellung.cStatus of a cancelled order (JTL define BESTELLUNG_STATUS_STORNO). */
    public const STATUS_STORNO = '-1';
    public const STATUS_VERSANDT = '4';
    public const STATUS_TEILVERSANDT = '5';

    public function __construct(private Connection $db, private int $shopId = 0)
    {
    }

    public function findOrderId(string $everysizeOrderId): ?int
    {
        $id = $this->db->getScalar(
            'SELECT kBestellung FROM xplugin_everysize_checkout_order WHERE everysize_order_id = :esId',
            ['esId' => $everysizeOrderId]
        );

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return object{kBestellung: int, everysize_order_id: string, paypal_capture_id: string, original_email: string, number_reported: ?string, created_at: string}|null
     */
    public function getLink(int $orderId): ?object
    {
        return $this->db->getSingleObject(
            'SELECT kBestellung, everysize_order_id, paypal_capture_id, original_email, number_reported, created_at
             FROM xplugin_everysize_checkout_order WHERE kBestellung = :id',
            ['id' => $orderId]
        );
    }

    /**
     * everysize order IDs of the given JTL orders, for the order list.
     *
     * @param int[] $orderIds
     *
     * @return array<int, string> kBestellung => everysize order ID
     */
    public function getEverysizeOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
        if ($orderIds === []) {
            return [];
        }
        $params = [];
        $placeholders = [];
        foreach ($orderIds as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params['id' . $i] = $id;
        }
        $ids = [];
        foreach ($this->db->getObjects(
            'SELECT kBestellung, everysize_order_id FROM xplugin_everysize_checkout_order
             WHERE kBestellung IN (' . implode(',', $placeholders) . ')',
            $params
        ) as $row) {
            $ids[(int) $row->kBestellung] = (string) $row->everysize_order_id;
        }

        return $ids;
    }

    public function addOrder(int $orderId, string $everysizeOrderId, string $payPalCaptureId, string $originalEmail = ''): void
    {
        $this->db->insert('xplugin_everysize_checkout_order', [
            'kBestellung' => $orderId,
            'everysize_order_id' => $everysizeOrderId,
            'paypal_capture_id' => $payPalCaptureId,
            'original_email' => mb_substr($originalEmail, 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function addOrderItem(int $orderPositionId, int $orderId, string $sku): void
    {
        $this->db->insert('xplugin_everysize_checkout_item', [
            'kWarenkorbPos' => $orderPositionId,
            'kBestellung' => $orderId,
            'sku' => mb_substr($sku, 0, 255),
        ]);
    }

    public function markNumberReported(int $orderId): void
    {
        $this->db->update(
            'xplugin_everysize_checkout_order',
            'kBestellung',
            $orderId,
            ['number_reported' => date('Y-m-d H:i:s')]
        );
    }

    public function isNumberReported(int $orderId): bool
    {
        return $this->db->getScalar(
            'SELECT 1 FROM xplugin_everysize_checkout_order WHERE kBestellung = :id AND number_reported IS NOT NULL',
            ['id' => $orderId]
        ) !== null;
    }

    /** cBestellNr of a JTL order, for reporting the number to everysize. */
    public function getOrderNumber(int $orderId): string
    {
        return (string) ($this->db->getScalar(
            'SELECT cBestellNr FROM tbestellung WHERE kBestellung = :id',
            ['id' => $orderId]
        ) ?? '');
    }

    public function addReport(int $orderId, string $kind, string $sku, string $reason, string $result): void
    {
        $this->db->insert('xplugin_everysize_checkout_report', [
            'kBestellung' => $orderId,
            'kind' => $kind,
            'sku' => $sku,
            'reason' => $reason,
            'result' => $result,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function hasSentFulfillment(int $orderId): bool
    {
        return $this->db->getScalar(
            'SELECT 1 FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind AND result = :result',
            ['id' => $orderId, 'kind' => self::KIND_FULFILLMENT, 'result' => self::RESULT_SENT]
        ) !== null;
    }

    /**
     * The order was already revoked at everysize: a sent revocation that was
     * NOT a return (MERCHANT_DECLINE or CUSTOMER_REVOKE) — after that, no
     * further revocations or returns can be reported.
     */
    public function wasRevoked(int $orderId): bool
    {
        return $this->db->getScalar(
            'SELECT 1 FROM xplugin_everysize_checkout_report
             WHERE kBestellung = :id AND kind = :kind AND reason <> :retour AND result = :result',
            ['id' => $orderId, 'kind' => self::KIND_REVOCATION, 'retour' => Api\Client::REASON_RETOUR, 'result' => self::RESULT_SENT]
        ) !== null;
    }

    /**
     * The SKUs the everysize order has (from the import) — a retour POST may
     * only name these.
     *
     * @return string[]
     */
    public function getSkus(int $orderId): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->sku,
            $this->db->getObjects('SELECT sku FROM xplugin_everysize_checkout_item WHERE kBestellung = :id', ['id' => $orderId])
        );
    }

    /**
     * SKUs of the order that already have a sent report of the given kind
     * and reason — a second report for them would hit the (order, kind,
     * sku) unique key.
     *
     * @return string[]
     */
    public function getReportedSkus(int $orderId, string $kind, string $reason): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->sku,
            $this->db->getObjects(
                'SELECT sku FROM xplugin_everysize_checkout_report
                 WHERE kBestellung = :id AND kind = :kind AND reason = :reason AND result = :result',
                ['id' => $orderId, 'kind' => $kind, 'reason' => $reason, 'result' => self::RESULT_SENT]
            )
        );
    }

    /**
     * @return array<int, object{kind: string, sku: string, reason: string, result: string, created_at: string}>
     */
    public function getReports(int $orderId): array
    {
        return $this->db->getObjects(
            'SELECT kind, sku, reason, result, created_at FROM xplugin_everysize_checkout_report
             WHERE kBestellung = :id ORDER BY kReport',
            ['id' => $orderId]
        );
    }

    /**
     * The tracking code the merchant entered on the plugin's order page, with
     * the carrier picked there. Null without an entry.
     *
     * @return object{carrier: string, tracking_code: string}|null
     */
    public function getManualTracking(int $orderId): ?object
    {
        return $this->db->getSingleObject(
            'SELECT carrier, tracking_code FROM xplugin_everysize_checkout_tracking WHERE kBestellung = :id',
            ['id' => $orderId]
        );
    }

    public function setManualTracking(int $orderId, string $carrier, string $trackingCode): void
    {
        $exists = $this->db->getSingleObject(
            'SELECT kTracking FROM xplugin_everysize_checkout_tracking WHERE kBestellung = :id',
            ['id' => $orderId]
        );
        if ($exists === null) {
            $this->db->insert('xplugin_everysize_checkout_tracking', [
                'kBestellung' => $orderId,
                'carrier' => mb_substr($carrier, 0, 31),
                'tracking_code' => mb_substr(trim($trackingCode), 0, 255),
            ]);
        } else {
            $this->db->update('xplugin_everysize_checkout_tracking', 'kTracking', (int) $exists->kTracking, [
                'carrier' => mb_substr($carrier, 0, 31),
                'tracking_code' => mb_substr(trim($trackingCode), 0, 255),
            ]);
        }
    }

    /**
     * Shipped orders (status versandt/teilversandt or a delivery note with
     * shipping) that still have an item and whose fulfillment has not been
     * reported. The tracking code comes from the plugin's order page, else
     * from the Wawi delivery note; the carrier analogously (raw JTL spelling
     * for the Wawi — the exporter maps it via the settings).
     *
     * @return array<int, object{kBestellung: int, everysize_order_id: string, cBestellNr: string, tracking_code: string, manual_carrier: string, wawi_carrier: string}>
     */
    /**
     * Shipped orders whose fulfillment has not been reported. Shipping is the
     * status versandt/teilversandt, a delivery note with shipping (the way
     * JTL-Wawi feeds the shop) or the JTL shipping date — the latter two
     * survive a cancellation: an order shipped and cancelled in the same
     * interval is still reported as shipped here; the revocation loop
     * reports the RETOUR afterwards (order of the loops in StatusExporter).
     *
     * @return array<int, object{kBestellung: int, everysize_order_id: string, cBestellNr: string, tracking_code: string, manual_carrier: string, wawi_carrier: string}>
     */
    public function getPendingFulfillments(): array
    {
        return $this->db->getObjects(
            "SELECT b.kBestellung, l.everysize_order_id, b.cBestellNr,
                    COALESCE(NULLIF(TRIM(t.tracking_code), ''),
                             (SELECT TRIM(v.cIdentCode) FROM tlieferschein dl
                              JOIN tversand v ON v.kLieferschein = dl.kLieferschein
                              WHERE dl.kInetBestellung = b.kBestellung AND TRIM(v.cIdentCode) <> ''
                              ORDER BY dl.kLieferschein LIMIT 1), '') AS tracking_code,
                    COALESCE(NULLIF(t.carrier, ''), '') AS manual_carrier,
                    COALESCE((SELECT v2.cLogistik FROM tlieferschein dl2
                              JOIN tversand v2 ON v2.kLieferschein = dl2.kLieferschein
                              WHERE dl2.kInetBestellung = b.kBestellung AND TRIM(v2.cIdentCode) <> ''
                              ORDER BY dl2.kLieferschein LIMIT 1), '') AS wawi_carrier
             FROM xplugin_everysize_checkout_order l
             JOIN tbestellung b ON b.kBestellung = l.kBestellung
             LEFT JOIN xplugin_everysize_checkout_tracking t ON t.kBestellung = l.kBestellung
             WHERE (b.cStatus IN (:versandt, :teilversandt)
                    OR b.dVersandDatum IS NOT NULL
                    OR EXISTS (SELECT 1 FROM tlieferschein dl3
                               JOIN tversand v3 ON v3.kLieferschein = dl3.kLieferschein
                               WHERE dl3.kInetBestellung = b.kBestellung))
               AND EXISTS (SELECT 1 FROM xplugin_everysize_checkout_item i WHERE i.kBestellung = l.kBestellung)
               AND NOT EXISTS (SELECT 1 FROM xplugin_everysize_checkout_report r
                               WHERE r.kBestellung = l.kBestellung AND r.kind = :kind)
             ORDER BY b.cBestellNr",
            [
                'versandt' => self::STATUS_VERSANDT,
                'teilversandt' => self::STATUS_TEILVERSANDT,
                'kind' => self::KIND_FULFILLMENT,
            ]
        );
    }

    /**
     * Orders whose fulfillment was reported since $since and that now carry a
     * tracking code that was never reported: entered after shipping on the
     * plugin's order page or arriving later from the Wawi. Cancelled orders
     * are left out.
     *
     * @return array<int, object{kBestellung: int, everysize_order_id: string, cBestellNr: string, tracking_code: string, manual_carrier: string, wawi_carrier: string}>
     */
    public function getPendingTrackingCodes(string $since): array
    {
        $params = [
            'since' => $since,
            'fulfillment' => self::KIND_FULFILLMENT,
            'sent' => self::RESULT_SENT,
            'storno' => self::STATUS_STORNO,
            'tracking' => self::KIND_TRACKING,
        ];

        return $this->db->getObjects(
            "(SELECT b.kBestellung, l.everysize_order_id, b.cBestellNr, TRIM(t.tracking_code) AS tracking_code,
                     COALESCE(NULLIF(t.carrier, ''), '') AS manual_carrier, '' AS wawi_carrier
              FROM xplugin_everysize_checkout_tracking t
              JOIN tbestellung b ON b.kBestellung = t.kBestellung
              JOIN xplugin_everysize_checkout_order l ON l.kBestellung = b.kBestellung
              JOIN xplugin_everysize_checkout_report f ON f.kBestellung = b.kBestellung
                  AND f.kind = :fulfillment AND f.result = :sent
              WHERE f.created_at >= :since
                AND (b.cStatus IS NULL OR b.cStatus <> :storno)
                AND TRIM(t.tracking_code) <> ''
                AND NOT EXISTS (SELECT 1 FROM xplugin_everysize_checkout_report r
                                WHERE r.kBestellung = b.kBestellung AND r.kind = :tracking AND r.sku = TRIM(t.tracking_code)))
             UNION ALL
             (SELECT b.kBestellung, l.everysize_order_id, b.cBestellNr, TRIM(v.cIdentCode) AS tracking_code,
                     '' AS manual_carrier, v.cLogistik AS wawi_carrier
              FROM tlieferschein dl
              JOIN tversand v ON v.kLieferschein = dl.kLieferschein
              JOIN tbestellung b ON b.kBestellung = dl.kInetBestellung
              JOIN xplugin_everysize_checkout_order l ON l.kBestellung = b.kBestellung
              JOIN xplugin_everysize_checkout_report f ON f.kBestellung = b.kBestellung
                  AND f.kind = :fulfillment AND f.result = :sent
              WHERE f.created_at >= :since
                AND (b.cStatus IS NULL OR b.cStatus <> :storno)
                AND TRIM(v.cIdentCode) <> ''
                AND NOT EXISTS (SELECT 1 FROM xplugin_everysize_checkout_tracking t
                                WHERE t.kBestellung = b.kBestellung AND TRIM(t.tracking_code) <> '')
                AND NOT EXISTS (SELECT 1 FROM xplugin_everysize_checkout_report r
                                WHERE r.kBestellung = b.kBestellung AND r.kind = :tracking AND r.sku = TRIM(v.cIdentCode)))
             ORDER BY cBestellNr, tracking_code",
            $params
        );
    }

    /**
     * Orders whose cancellation before shipping (MERCHANT_DECLINE) was reported
     * since $since, with no position left that is not an everysize item, and
     * that have no automatic refund yet — neither done nor given up. An
     * everysize order has one product; a position the merchant or the Wawi
     * added keeps the order from being refunded in full. Orders with a
     * delivery note plus shipping (shipped before the cancellation was seen)
     * or a non-cancelled status are excluded: shipped goods are a RETOUR
     * case, and a cancellation taken back by the Wawi is not refunded.
     *
     * @return array<int, object{kBestellung: int, everysize_order_id: string, cBestellNr: string}>
     */
    public function getPendingAutoRefunds(string $since): array
    {
        return $this->db->getObjects(
            "SELECT DISTINCT b.kBestellung, l.everysize_order_id, b.cBestellNr
             FROM xplugin_everysize_checkout_report r
             JOIN tbestellung b ON b.kBestellung = r.kBestellung
             JOIN xplugin_everysize_checkout_order l ON l.kBestellung = r.kBestellung
             WHERE l.paypal_capture_id <> ''
               AND b.cStatus = :storno
               AND r.kind = :revocation AND r.reason = :decline AND r.result = :sent
               AND r.created_at >= :since
               AND b.dVersandDatum IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM tlieferschein dl
                   JOIN tversand v ON v.kLieferschein = dl.kLieferschein
                   WHERE dl.kInetBestellung = b.kBestellung
               )
               AND NOT EXISTS (
                   SELECT 1 FROM twarenkorbpos p
                   WHERE p.kWarenkorb = b.kWarenkorb AND p.nPosTyp = 1
                     AND NOT EXISTS (SELECT 1 FROM xplugin_everysize_checkout_item i
                                     WHERE i.kWarenkorbPos = p.kWarenkorbPos)
               )
               AND NOT EXISTS (
                   SELECT 1 FROM xplugin_everysize_checkout_report a
                   WHERE a.kBestellung = r.kBestellung AND a.kind = :refund
               )
             ORDER BY b.cBestellNr",
            [
                'storno' => self::STATUS_STORNO,
                'revocation' => self::KIND_REVOCATION,
                'decline' => Api\Client::REASON_MERCHANT_DECLINE,
                'sent' => self::RESULT_SENT,
                'since' => $since,
                'refund' => self::KIND_REFUND,
            ]
        );
    }

    /**
     * Cancelled orders whose revocation has not been reported. JTL-Wawi marks
     * a cancellation by tbestellung.cStatus = -1 (item-level cancellations do
     * not exist in JTL); the SKU of every remembered item is revoked.
     *
     * shipped marks orders that were shipped before the cancellation reached
     * the shop (a delivery note with shipping, or the JTL shipping date —
     * cStatus is overwritten by the cancellation, so those are the lasting
     * proofs): they are reported as RETOUR, not MERCHANT_DECLINE, and are
     * not refunded automatically.
     *
     * @return array<int, object{ORDERID: int, everysize_order_id: string, cBestellNr: string, sku: string, shipped: int}>
     */
    public function getPendingRevocations(): array
    {
        return $this->db->getObjects(
            "SELECT o.kBestellung AS ORDERID, l.everysize_order_id, o.cBestellNr, i.sku,
                    (EXISTS (SELECT 1 FROM tlieferschein dl
                             JOIN tversand v ON v.kLieferschein = dl.kLieferschein
                             WHERE dl.kInetBestellung = o.kBestellung)
                     OR o.dVersandDatum IS NOT NULL) AS shipped
             FROM xplugin_everysize_checkout_item i
             JOIN tbestellung o ON o.kBestellung = i.kBestellung
             JOIN xplugin_everysize_checkout_order l ON l.kBestellung = o.kBestellung
             WHERE o.cStatus = :storno
               AND NOT EXISTS (
                   SELECT 1 FROM xplugin_everysize_checkout_report r
                   WHERE r.kBestellung = o.kBestellung AND r.kind = :kind AND r.sku = i.sku
               )
             ORDER BY o.cBestellNr",
            ['storno' => self::STATUS_STORNO, 'kind' => self::KIND_REVOCATION]
        );
    }
}
