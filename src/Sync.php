<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout;

use JTL\DB\DbInterface;
use Plugin\everysize_checkout\Api\ApiException;
use Plugin\everysize_checkout\Api\Client;
use Plugin\everysize_checkout\Api\CurlTransport;
use Plugin\everysize_checkout\Db\JtlConnection;
use Plugin\everysize_checkout\Export\StatusExporter;
use Plugin\everysize_checkout\Import\ImportException;
use Plugin\everysize_checkout\Import\OrderImporter;
use Plugin\everysize_checkout\Shop\JtlShopGateway;

/**
 * One sync run: import new everysize orders, report their order numbers,
 * then report shipping/cancellations/returns. Run by cron (webcron, JTL cron
 * job) and through the console command.
 */
final class Sync
{
    private const LOCK_NAME = 'everysize_checkout_sync';

    private Settings $settings;

    private Client $client;

    private OrderImporter $importer;

    private StatusExporter $exporter;

    private OrderLinks $links;

    private Protocol $protocol;

    public function __construct(
        Settings $settings,
        Client $client,
        OrderImporter $importer,
        StatusExporter $exporter,
        OrderLinks $links,
        Protocol $protocol
    ) {
        $this->settings = $settings;
        $this->client = $client;
        $this->importer = $importer;
        $this->exporter = $exporter;
        $this->links = $links;
        $this->protocol = $protocol;
    }

    /**
     * Builds only the API client, for the admin actions (retour, refund)
     * that do not need a full sync. The admin answers the browser, so the
     * timeouts are short (3 s connect / 5 s total); the sync keeps 10/30.
     */
    public static function createClient(DbInterface $db, int $pluginId): Client
    {
        $settings = new Settings(new JtlConnection($db), 0);

        return new Client(
            $settings->getClientId(),
            $settings->getClientSecret(),
            '',
            \defined('APPLICATION_VERSION') ? APPLICATION_VERSION : 'unknown',
            PluginInfo::VERSION,
            new CurlTransport(3, 5)
        );
    }

    /**
     * Builds a sync with the shop-runtime collaborators (JTL gateway for
     * crypto/order numbers/tax/mail). For tests, construct manually with
     * fakes instead.
     *
     * @param DbInterface $db the shop connection (wrapped for the plugin core)
     */
    public static function create(DbInterface $db, int $pluginId): self
    {
        $connection = new JtlConnection($db);
        $settings = new Settings($connection, 0);
        $client = new Client($settings->getClientId(), $settings->getClientSecret(), '', APPLICATION_VERSION, PluginInfo::VERSION);
        $links = new OrderLinks($connection);

        return new self(
            $settings,
            $client,
            new OrderImporter($connection, $pluginId, $settings, $links, new JtlShopGateway()),
            new StatusExporter($client, $settings, $links),
            $links,
            new Protocol($connection)
        );
    }

    /**
     * @return array{messages: string[], errors: string[]}
     */
    public function run(bool $import = true, bool $export = true): array
    {
        if (!$this->settings->isConfigured()) {
            return ['messages' => ['everysize credentials not configured, nothing to do'], 'errors' => []];
        }
        if (!$this->settings->isSyncEnabled()) {
            return ['messages' => ['everysize sync is disabled in the plugin settings'], 'errors' => []];
        }

        $db = $this->settings->getDb();
        if ((int) $db->getScalar('SELECT GET_LOCK(:name, 0)', ['name' => self::LOCK_NAME]) !== 1) {
            return ['messages' => ['another everysize sync is still running, skipped'], 'errors' => []];
        }

        try {
            try {
                $apiUrl = $this->client->getApiUrl();
            } catch (ApiException $e) {
                $this->protocol->add('everysize API URL invalid', $e->getMessage(), false);

                return ['messages' => [], 'errors' => [$e->getMessage()]];
            }

            $result = ['messages' => [sprintf('everysize API: %s', $apiUrl)], 'errors' => []];
            // One broken direction must not stop the other: import and
            // export each catch everything and log it, the run continues.
            if ($import) {
                try {
                    $result = $this->merge($result, $this->import());
                } catch (\Throwable $e) {
                    $error = sprintf('Import failed: %s: %s', \get_class($e), $e->getMessage());
                    $this->protocol->add('Import failed', $error, false);
                    $result['errors'][] = $error;
                }
            }
            if ($export) {
                try {
                    $result = $this->merge($result, $this->export());
                } catch (\Throwable $e) {
                    $error = sprintf('Report failed: %s: %s', \get_class($e), $e->getMessage());
                    $this->protocol->add('Report to everysize failed', $error, false);
                    $result['errors'][] = $error;
                }
            }
            $this->protocol->cleanup();
            $this->settings->set(Settings::LAST_SYNC, date('Y-m-d H:i:s'));

            return $result;
        } finally {
            $db->getScalar('SELECT RELEASE_LOCK(:name)', ['name' => self::LOCK_NAME]);
        }
    }

    /**
     * @return array{messages: string[], errors: string[]}
     */
    private function import(): array
    {
        try {
            $orders = $this->client->getOpenOrders();
        } catch (ApiException $e) {
            $this->protocol->add('Import from everysize failed', $e->getMessage(), false);

            return ['messages' => [], 'errors' => [$e->getMessage()]];
        }

        $imported = 0;
        $errors = [];
        $warnings = [];
        foreach ($orders as $esOrder) {
            $everysizeOrderId = trim((string) ($esOrder['everysizeOrderId'] ?? ''));
            if ($everysizeOrderId === '') {
                $errors[] = 'everysize order without everysizeOrderId skipped';
                continue;
            }

            try {
                $orderId = $this->links->findOrderId($everysizeOrderId);
                if ($orderId === null) {
                    $result = $this->importer->import($esOrder);
                    $orderId = $result['orderId'];
                    $warnings = array_merge($warnings, $result['warnings']);
                    ++$imported;
                }

                if (!$this->links->isNumberReported($orderId)) {
                    $this->client->sendMerchantOrderNumber($everysizeOrderId, $this->orderNumber($orderId));
                    $this->links->markNumberReported($orderId);
                }
            } catch (ImportException|ApiException $e) {
                $errors[] = sprintf('%s: %s', $everysizeOrderId, $e->getMessage());
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s: %s: %s', $everysizeOrderId, \get_class($e), $e->getMessage());
            }
        }

        $title = sprintf('Imported %d of %d orders from everysize', $imported, \count($orders));
        if ($orders !== [] || $errors !== []) {
            $this->protocol->add($title, implode("\n", array_merge($errors, $warnings)), $errors === []);
        }

        return ['messages' => [$title], 'errors' => $errors];
    }

    /**
     * @return array{messages: string[], errors: string[]}
     */
    private function export(): array
    {
        try {
            $result = $this->exporter->export();
        } catch (ApiException $e) {
            $this->protocol->add('Report to everysize failed', $e->getMessage(), false);

            return ['messages' => [], 'errors' => [$e->getMessage()]];
        }

        $title = sprintf(
            'Reported to everysize: %d shipped, %d tracking codes added later, %d cancelled, %d returned, %d refunded automatically, %d skipped (revoked at everysize)',
            $result['fulfillments'],
            $result['trackingCodes'],
            $result['revocations'],
            $result['returns'],
            $result['autoRefunds'],
            $result['skipped']
        );
        if ($result['pending'] > 0 || $result['errors'] !== []) {
            $this->protocol->add($title, implode("\n", $result['errors']), $result['errors'] === []);
        }

        return ['messages' => [$title], 'errors' => $result['errors']];
    }

    private function orderNumber(int $orderId): string
    {
        return $this->links->getOrderNumber($orderId);
    }

    /**
     * @param array{messages: string[], errors: string[]} $a
     * @param array{messages: string[], errors: string[]} $b
     *
     * @return array{messages: string[], errors: string[]}
     */
    private function merge(array $a, array $b): array
    {
        return [
            'messages' => array_merge($a['messages'], $b['messages']),
            'errors' => array_merge($a['errors'], $b['errors']),
        ];
    }
}
