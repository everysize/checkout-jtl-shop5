<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout;

use Plugin\everysize_checkout\Db\Connection;

/**
 * Protocol of sync runs, shown in the admin. Only runs that moved something
 * or failed are recorded — with a run every 5 minutes, "nothing to do" entries
 * would bury the relevant ones.
 */
class Protocol
{
    public const RETENTION_DAYS = 31;

    public function __construct(private Connection $db, private int $shopId = 0)
    {
    }

    public function add(string $title, string $message, bool $success): void
    {
        $this->db->insert('xplugin_everysize_checkout_protocol', [
            'shop_id' => $this->shopId(),
            'created_at' => date('Y-m-d H:i:s'),
            'title' => mb_substr($title, 0, 255),
            'message' => $message,
            'state' => $success ? 1 : 0,
        ]);
    }

    /**
     * @param bool|null $success null = all entries, true = only successful, false = only errors
     *
     * @return array<int, object{created_at: string, title: string, message: string, state: int}>
     */
    public function getEntries(int $offset = 0, int $limit = 50, ?bool $success = null): array
    {
        [$where, $params] = $this->filter($success);

        return $this->db->getObjects(
            sprintf(
                'SELECT created_at, title, message, state FROM xplugin_everysize_checkout_protocol WHERE %s ORDER BY kProtocol DESC LIMIT %d, %d',
                $where,
                max(0, $offset),
                max(1, $limit)
            ),
            $params
        );
    }

    public function count(?bool $success = null): int
    {
        [$where, $params] = $this->filter($success);

        return (int) ($this->db->getScalar(
            'SELECT COUNT(*) FROM xplugin_everysize_checkout_protocol WHERE ' . $where,
            $params
        ) ?? 0);
    }

    /** Deletes protocol entries older than the retention period, 1000 per call. */
    public function cleanup(int $days = self::RETENTION_DAYS): void
    {
        $this->db->execute(
            'DELETE FROM xplugin_everysize_checkout_protocol WHERE shop_id = :shopId AND created_at < :before LIMIT 1000',
            ['shopId' => $this->shopId(), 'before' => date('Y-m-d H:i:s', strtotime(sprintf('-%d days', $days)))]
        );
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    private function filter(?bool $success): array
    {
        $where = 'shop_id = :shopId';
        $params = ['shopId' => $this->shopId()];
        if ($success !== null) {
            $where .= ' AND state = :state';
            $params['state'] = $success ? 1 : 0;
        }

        return [$where, $params];
    }

    private function shopId(): int
    {
        return $this->shopId;
    }
}
