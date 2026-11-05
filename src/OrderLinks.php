<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout;

use Plugin\everysize_checkout\Api\Amount;
use Plugin\everysize_checkout\Api\ApiException;
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
    /**
     * Result of the open refund entry: the call was (or is about to be) sent and nobody knows its outcome.
     * Stored with the idempotency key (SKU), the amount (REASON, "rest" = everything not refunded yet).
     * Old reports with the result "unknown" are NOT open entries: display and automatic-refund block only.
     */
    public const RESULT_OPEN = 'open';
    /** The refund of this form key was refused with proof: the key is burned, the next try needs a new one (R2). */
    public const RESULT_REJECTED = 'rejected';
    /** The merchant marked an open entry as cleared (nothing was sent); the automatic refund stays off. */
    public const RESULT_CLEARED = 'cleared';
    /** Amount stored for a refund of "the rest". */
    public const AMOUNT_REST = 'rest';
    /**
     * Kind of the data row of the open entry. Deliberately NOT "refund": the success or refusal report often
     * carries the same key as SKU, and the unique key (order, kind, SKU) would reject it.
     */
    public const KIND_REFUND_OPEN = 'refundopen';
    /** Kind of the rows that hold the refundIds known when the entry was created (SKU = refundId). */
    public const KIND_REFUND_KNOWN = 'refundknown';
    /** Kind of the report that links a booked refund to the idempotency key (SKU = key, REASON = refundId). */
    public const KIND_REFUND_KEY = 'refundkey';
    /** Kind of the per-order lock row: exists exactly as long as an open refund entry does. */
    public const KIND_REFUND_LOCK = 'refundlock';
    /** Shipping report that failed (API down): the order was shipped, only everysize does not know yet. */
    public const KIND_FULFILLMENT_FAILED = 'fulfillfail';
    /** A refund entry is not touched by a second request within this many seconds (the first may still run). */
    public const REFUND_MIN_AGE = 150;
    /**
     * Marker of the cancellation check: the order is cancelled at everysize and the merchant was told (or needed no
     * hint). Unique per order (empty sku). reason is the kind of hint (OPEN or SHIPPED) or empty. At most 16
     * characters: the column kind.
     */
    public const KIND_REVOKED_AT_EVERYSIZE = 'es-revoked';
    /** A hint for the merchant was written for the order (KIND_REVOKED_AT_EVERYSIZE). */
    public const RESULT_NOTED = 'noted';
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

    public function hasReport(int $orderId, string $kind, string $sku): bool
    {
        return $this->db->getScalar(
            'SELECT 1 FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind AND sku = :sku',
            ['id' => $orderId, 'kind' => $kind, 'sku' => $sku]
        ) !== null;
    }

    /**
     * What the cancellation check needs to know about the shop order: number and language (de, else en), whether it is
     * closed (status cancelled, a cancellation this plugin reported itself, which means it came from the shop, or the
     * whole amount refunded through everysize: see isFullyRefunded; a partial refund does not close it) and whether
     * shipping shows (status shipped, shipping date, tracking code, a delivery note, a shipping report of any
     * result). $paidCents is what the customer paid (everysize grossPrice); without it the total of the shop order
     * counts. null: no such order any more.
     *
     * @return array{number: string, iso: string, closed: bool, shipped: bool}|null
     */
    public function getRevokedCheckInfo(int $orderId, ?int $paidCents = null): ?array
    {
        $row = $this->db->getSingleObject(
            "SELECT b.cBestellNr, b.cStatus, b.fGesamtsumme, s.cISO AS iso,
                    (b.cStatus IN (:versandt, :teilversandt)
                     OR b.dVersandDatum IS NOT NULL
                     OR TRIM(COALESCE(b.cTracking, '')) <> ''
                     OR EXISTS (SELECT 1 FROM xplugin_everysize_checkout_tracking t WHERE t.kBestellung = b.kBestellung AND TRIM(t.tracking_code) <> '')
                     OR EXISTS (SELECT 1 FROM tlieferschein dl WHERE dl.kInetBestellung = b.kBestellung)
                     OR EXISTS (SELECT 1 FROM xplugin_everysize_checkout_report sr
                                WHERE sr.kBestellung = b.kBestellung AND sr.kind IN (:fulfillment, :tracking, :fulfillfail))) AS shipped,
                    (SELECT COUNT(*) FROM xplugin_everysize_checkout_report r
                     WHERE r.kBestellung = b.kBestellung
                       AND r.kind = :revocation) AS closed_reports
             FROM tbestellung b
             LEFT JOIN tsprache s ON s.kSprache = b.kSprache
             WHERE b.kBestellung = :id",
            [
                'id' => $orderId,
                'versandt' => self::STATUS_VERSANDT,
                'teilversandt' => self::STATUS_TEILVERSANDT,
                'fulfillment' => self::KIND_FULFILLMENT,
                'tracking' => self::KIND_TRACKING,
                'fulfillfail' => self::KIND_FULFILLMENT_FAILED,
                'revocation' => self::KIND_REVOCATION,
            ]
        );
        if ($row === null) {
            return null;
        }

        $closed = (string) $row->cStatus === self::STATUS_STORNO || (int) $row->closed_reports > 0;
        if (!$closed) {
            $paid = $paidCents !== null && $paidCents > 0 ? $paidCents : (int) round((float) $row->fGesamtsumme * 100);
            $closed = $this->isFullyRefunded($orderId, $paid);
        }

        return [
            'number' => (string) $row->cBestellNr,
            'iso' => strtolower((string) ($row->iso ?? '')) === 'ger' ? 'de' : 'en',
            'closed' => $closed,
            'shipped' => (int) $row->shipped === 1,
        ];
    }

    /**
     * The refunds through everysize that were reported for the order (result sent) add up to the whole amount
     * paid. The automatic refund after a cancellation is always the whole amount. Open or unclear refunds (open
     * entry, cleared, known, rejected) count for nothing. Compared in cents.
     */
    public function isFullyRefunded(int $orderId, int $paidCents): bool
    {
        $rows = $this->db->getObjects(
            'SELECT sku, reason FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind AND result = :sent',
            ['id' => $orderId, 'kind' => self::KIND_REFUND, 'sent' => self::RESULT_SENT]
        );

        return self::coversPaid(array_map(static fn (object $r): array => ['sku' => (string) $r->sku, 'reason' => (string) $r->reason], $rows), $paidCents);
    }

    /**
     * @param array<int, array{sku: string, reason: string}> $refundReports reports of kind refund with result sent
     */
    public static function coversPaid(array $refundReports, int $paidCents): bool
    {
        $sum = 0;
        foreach ($refundReports as $report) {
            if ($report['sku'] === self::AUTO_REFUND) {
                return true;
            }
            try {
                $sum += Amount::cents($report['reason']);
            } catch (ApiException) {
                // no readable amount: it counts for nothing
            }
        }

        return $paidCents > 0 && $sum >= $paidCents;
    }

    /**
     * Orders of the given list that carry the marker of the cancellation check with a hint (for the order page).
     * Orders that are closed by now (cancelled, refunded in full) are left out: nothing is left to warn about.
     *
     * @param array<int, int> $orderIds
     *
     * @return array<int, string> order id => REASON (OPEN or SHIPPED)
     */
    public function getRevokedNotes(array $orderIds): array
    {
        $notes = [];
        foreach ($this->getRevokedMarkers($orderIds) as $orderId => $reason) {
            $info = $this->getRevokedCheckInfo($orderId);
            if ($info !== null && !$info['closed']) {
                $notes[$orderId] = $reason;
            }
        }

        return $notes;
    }

    /**
     * @param array<int, int> $orderIds
     *
     * @return array<int, string> order id => REASON of the markers that carry a hint
     */
    protected function getRevokedMarkers(array $orderIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $orderIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $markers = [];
        foreach ($this->db->getObjects(
            'SELECT kBestellung, reason FROM xplugin_everysize_checkout_report WHERE kind = :kind AND result = :noted AND kBestellung IN (' . implode(',', $ids) . ')',
            ['kind' => self::KIND_REVOKED_AT_EVERYSIZE, 'noted' => self::RESULT_NOTED]
        ) as $row) {
            $markers[(int) $row->kBestellung] = (string) $row->reason;
        }

        return $markers;
    }

    /**
     * Removes a report, e.g. the open refund call once its outcome is known.
     */
    public function deleteReport(int $orderId, string $kind, string $sku): void
    {
        $this->db->execute(
            'DELETE FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind AND sku = :sku',
            ['id' => $orderId, 'kind' => $kind, 'sku' => $sku]
        );
    }

    /**
     * The open refund entry of the order, or null. Exactly one per order: the lock row (kind refundlock,
     * unique per order) is its existence, the refund row (kind refund, result open) its data.
     * "key" is empty for a lock without data (a crashed request) - only "mark as cleared" is left then.
     *
     * @return object{key: string, amount: string, created_at: string, sent_at: string, token: string}|null
     */
    public function openRefund(int $orderId): ?object
    {
        $lock = $this->db->getSingleObject(
            'SELECT reason, created_at FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind',
            ['id' => $orderId, 'kind' => self::KIND_REFUND_LOCK]
        );
        if ($lock === null) {
            return null;
        }
        $data = $this->db->getSingleObject(
            'SELECT sku, reason, created_at FROM xplugin_everysize_checkout_report
             WHERE kBestellung = :id AND kind = :kind AND result = :result ORDER BY kReport LIMIT 1',
            ['id' => $orderId, 'kind' => self::KIND_REFUND_OPEN, 'result' => self::RESULT_OPEN]
        );

        return (object) [
            'key' => $data !== null ? (string) $data->sku : '',
            'amount' => $data !== null ? (string) $data->reason : '',
            'created_at' => $data !== null ? (string) $data->created_at : (string) $lock->created_at,
            'sent_at' => (string) $lock->created_at,
            'token' => (string) $lock->reason,
        ];
    }

    /**
     * Stores a new open refund entry BEFORE the call is sent. False (nothing may be sent) if another entry is
     * open or a parallel request won the lock, or if storing fails. $amount null = the rest.
     */
    public function claimNewRefund(int $orderId, string $key, ?string $amount): bool
    {
        if ($this->openRefund($orderId) !== null) {
            return false;
        }
        $token = bin2hex(random_bytes(8));
        try {
            $this->db->execute(
                'INSERT IGNORE INTO xplugin_everysize_checkout_report (kBestellung, kind, sku, reason, result, created_at)
                 VALUES (:id, :kind, :sku, :token, :result, :now)',
                ['id' => $orderId, 'kind' => self::KIND_REFUND_LOCK, 'sku' => '', 'token' => $token, 'result' => 'lock', 'now' => date('Y-m-d H:i:s')]
            );
            $held = $this->db->getScalar(
                'SELECT reason FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind',
                ['id' => $orderId, 'kind' => self::KIND_REFUND_LOCK]
            );
            if ((string) $held !== $token) {
                return false;
            }
            $this->db->insert('xplugin_everysize_checkout_report', [
                'kBestellung' => $orderId,
                'kind' => self::KIND_REFUND_OPEN,
                'sku' => $key,
                'reason' => $amount ?? self::AMOUNT_REST,
                'result' => self::RESULT_OPEN,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            $this->releaseLock($orderId, $token);

            return false;
        }

        return true;
    }

    /**
     * Takes over an open entry for a repeated send: swaps the token and stamps the send time in one conditional
     * UPDATE, so of two parallel requests only one gets true.
     */
    public function claimRetry(int $orderId, string $oldToken, string $oldSentAt): bool
    {
        $token = bin2hex(random_bytes(8));
        try {
            $this->db->execute(
                'UPDATE xplugin_everysize_checkout_report SET reason = :new, created_at = :now
                 WHERE kBestellung = :id AND kind = :kind AND reason = :old AND created_at = :oldsent',
                ['new' => $token, 'now' => date('Y-m-d H:i:s'), 'id' => $orderId, 'kind' => self::KIND_REFUND_LOCK, 'old' => $oldToken, 'oldsent' => $oldSentAt]
            );

            return (string) $this->db->getScalar(
                'SELECT reason FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind',
                ['id' => $orderId, 'kind' => self::KIND_REFUND_LOCK]
            ) === $token;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * "Mark as cleared": deletes the lock only with the matching token and a send time older than the cutoff - one
     * conditional DELETE, so a request that still runs (or took the entry over) is never cleared by accident.
     * True when the lock is gone afterwards.
     */
    public function claimClear(int $orderId, string $token, string $olderThan): bool
    {
        try {
            $this->db->execute(
                'DELETE FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind AND reason = :token AND created_at <= :cutoff',
                ['id' => $orderId, 'kind' => self::KIND_REFUND_LOCK, 'token' => $token, 'cutoff' => $olderThan]
            );

            return $this->db->getScalar(
                'SELECT 1 FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind',
                ['id' => $orderId, 'kind' => self::KIND_REFUND_LOCK]
            ) === null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Stores the refundIds the list held when the entry was created (A3/A4): only a refund that is NOT among
     * them can be the one this entry sent.
     *
     * @param string[] $ids
     */
    public function saveKnownRefunds(int $orderId, array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            $this->db->execute(
                'INSERT IGNORE INTO xplugin_everysize_checkout_report (kBestellung, kind, sku, reason, result, created_at)
                 VALUES (:id, :kind, :sku, :reason, :result, :now)',
                ['id' => $orderId, 'kind' => self::KIND_REFUND_KNOWN, 'sku' => $id, 'reason' => '', 'result' => 'known', 'now' => date('Y-m-d H:i:s')]
            );
        }
    }

    /** @return string[] */
    public function knownRefundIds(int $orderId): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->sku,
            $this->db->getObjects(
                'SELECT sku FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind',
                ['id' => $orderId, 'kind' => self::KIND_REFUND_KNOWN]
            )
        );
    }

    /** Closes the open entry (after the outcome report was written): refund row first, then the lock. */
    public function closeRefund(int $orderId): void
    {
        $this->db->execute(
            'DELETE FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind AND result = :result',
            ['id' => $orderId, 'kind' => self::KIND_REFUND_OPEN, 'result' => self::RESULT_OPEN]
        );
        $this->db->execute(
            'DELETE FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind',
            ['id' => $orderId, 'kind' => self::KIND_REFUND_KNOWN]
        );
        $this->db->execute(
            'DELETE FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind',
            ['id' => $orderId, 'kind' => self::KIND_REFUND_LOCK]
        );
    }

    /** Removes the data of an entry whose lock is already gone (after claimClear). */
    public function dropEntryData(int $orderId): void
    {
        foreach ([self::KIND_REFUND_OPEN, self::KIND_REFUND_KNOWN] as $kind) {
            $this->db->execute(
                'DELETE FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind',
                ['id' => $orderId, 'kind' => $kind]
            );
        }
    }

    /** The refund of this idempotency key was reported as booked (kind refundkey). */
    public function hasKeyReport(int $orderId, string $key): bool
    {
        return $this->db->getScalar(
            'SELECT 1 FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind AND sku = :sku',
            ['id' => $orderId, 'kind' => self::KIND_REFUND_KEY, 'sku' => $key]
        ) !== null;
    }

    private function releaseLock(int $orderId, string $token): void
    {
        try {
            $this->db->execute(
                'DELETE FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind AND reason = :token',
                ['id' => $orderId, 'kind' => self::KIND_REFUND_LOCK, 'token' => $token]
            );
        } catch (\Throwable) {
            // stays; the entry then only offers "mark as cleared"
        }
    }

    /**
     * Orders with an open refund entry, oldest first (automatic runs resolve their own and report old ones).
     *
     * @return array<int, object{kBestellung: int, everysize_order_id: string, cBestellNr: string}>
     */
    public function getOpenRefundOrders(): array
    {
        return $this->db->getObjects(
            "SELECT b.kBestellung, l.everysize_order_id, b.cBestellNr
             FROM xplugin_everysize_checkout_report k
             JOIN xplugin_everysize_checkout_order l ON l.kBestellung = k.kBestellung
             JOIN tbestellung b ON b.kBestellung = k.kBestellung
             WHERE k.kind = :kind ORDER BY k.created_at",
            ['kind' => self::KIND_REFUND_LOCK]
        );
    }

    /** A shipping report failed for this order (kept as proof of shipping). */
    public function markFulfillmentFailed(int $orderId): void
    {
        if ($this->hasFailedFulfillment($orderId)) {
            return;
        }
        $this->addReport($orderId, self::KIND_FULFILLMENT_FAILED, '', '', 'failed');
    }

    /** Any fulfillment report (sent, skipped): the shipping report is done, nothing is left to make up. */
    public function hasFulfillmentReport(int $orderId): bool
    {
        return $this->db->getScalar(
            'SELECT 1 FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind',
            ['id' => $orderId, 'kind' => self::KIND_FULFILLMENT]
        ) !== null;
    }

    public function hasFailedFulfillment(int $orderId): bool
    {
        return $this->db->getScalar(
            'SELECT 1 FROM xplugin_everysize_checkout_report WHERE kBestellung = :id AND kind = :kind',
            ['id' => $orderId, 'kind' => self::KIND_FULFILLMENT_FAILED]
        ) !== null;
    }

    /**
     * A tracking code entered on the order page counts as shipping, like in
     * the order page ("gilt als Versand"): the goods may be on their way
     * although the Wawi never booked them.
     */
    public function hasManualTracking(int $orderId): bool
    {
        return $this->db->getScalar(
            "SELECT 1 FROM xplugin_everysize_checkout_tracking WHERE kBestellung = :id AND TRIM(tracking_code) <> ''",
            ['id' => $orderId]
        ) !== null;
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
                    OR TRIM(COALESCE(t.tracking_code, '')) <> ''
                    OR EXISTS (SELECT 1 FROM tlieferschein dl3 WHERE dl3.kInetBestellung = b.kBestellung))
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
     * delivery note plus shipping (shipped before the cancellation was seen),
     * a shipping date, a tracking code (Wawi or entered by hand) or a reported
     * fulfillment, or a non-cancelled status are excluded: shipped goods are
     * a RETOUR case, and a cancellation taken back by the Wawi is not refunded.
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
               AND TRIM(COALESCE(b.cTracking, '')) = ''
               AND NOT EXISTS (SELECT 1 FROM tlieferschein dl WHERE dl.kInetBestellung = b.kBestellung)
               AND NOT EXISTS (
                   SELECT 1 FROM xplugin_everysize_checkout_tracking t
                   WHERE t.kBestellung = b.kBestellung AND TRIM(t.tracking_code) <> ''
               )
               AND NOT EXISTS (
                   SELECT 1 FROM xplugin_everysize_checkout_report f
                   WHERE f.kBestellung = b.kBestellung AND f.kind IN (:fulfillment, :fulfillfail)
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
                'fulfillment' => self::KIND_FULFILLMENT,
                'fulfillfail' => self::KIND_FULFILLMENT_FAILED,
            ]
        );
    }

    /**
     * Cancellations before shipping (MERCHANT_DECLINE reported since $since) that have no refund report and no open
     * entry yet - whether or not they are automatic-refund candidates. The exporter subtracts the candidates and
     * tells the merchant, once, about the others (A13).
     *
     * @return array<int, object{kBestellung: int, everysize_order_id: string, cBestellNr: string}>
     */
    public function getCancelledDeclines(string $since): array
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
               AND NOT EXISTS (SELECT 1 FROM xplugin_everysize_checkout_report a WHERE a.kBestellung = r.kBestellung AND a.kind = :refund)
               AND NOT EXISTS (SELECT 1 FROM xplugin_everysize_checkout_report k WHERE k.kBestellung = r.kBestellung AND k.kind = :lock)
             ORDER BY b.cBestellNr",
            [
                'storno' => self::STATUS_STORNO,
                'revocation' => self::KIND_REVOCATION,
                'decline' => Api\Client::REASON_MERCHANT_DECLINE,
                'sent' => self::RESULT_SENT,
                'since' => $since,
                'refund' => self::KIND_REFUND,
                'lock' => self::KIND_REFUND_LOCK,
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
     * proofs) or that carry a tracking code, also one entered by hand: they
     * are reported as RETOUR, not MERCHANT_DECLINE, and are not refunded
     * automatically.
     *
     * @return array<int, object{ORDERID: int, everysize_order_id: string, cBestellNr: string, sku: string, shipped: int}>
     */
    public function getPendingRevocations(): array
    {
        return $this->db->getObjects(
            "SELECT o.kBestellung AS ORDERID, l.everysize_order_id, o.cBestellNr, i.sku,
                    (EXISTS (SELECT 1 FROM tlieferschein dl WHERE dl.kInetBestellung = o.kBestellung)
                     OR o.dVersandDatum IS NOT NULL
                     OR EXISTS (SELECT 1 FROM xplugin_everysize_checkout_report ff
                                WHERE ff.kBestellung = o.kBestellung AND ff.kind = :fulfillfail)
                     OR TRIM(COALESCE(o.cTracking, '')) <> ''
                     OR EXISTS (SELECT 1 FROM xplugin_everysize_checkout_tracking mt
                                WHERE mt.kBestellung = o.kBestellung AND TRIM(mt.tracking_code) <> '')) AS shipped
             FROM xplugin_everysize_checkout_item i
             JOIN tbestellung o ON o.kBestellung = i.kBestellung
             JOIN xplugin_everysize_checkout_order l ON l.kBestellung = o.kBestellung
             WHERE o.cStatus = :storno
               AND NOT EXISTS (
                   SELECT 1 FROM xplugin_everysize_checkout_report r
                   WHERE r.kBestellung = o.kBestellung AND r.kind = :kind AND r.sku = i.sku
               )
             ORDER BY o.cBestellNr",
            ['storno' => self::STATUS_STORNO, 'kind' => self::KIND_REVOCATION, 'fulfillfail' => self::KIND_FULFILLMENT_FAILED]
        );
    }
}
