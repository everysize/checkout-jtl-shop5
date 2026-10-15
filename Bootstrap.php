<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout;

use JTL\Events\Dispatcher;
use JTL\Events\Event;
use JTL\Plugin\Bootstrapper;
use JTL\Router\Router;
use JTL\Shop;
use Laminas\Diactoros\Response\TextResponse;
use Plugin\everysize_checkout\CronJob\SyncCronJob;
use Plugin\everysize_checkout\Db\JtlConnection;
use Psr\Http\Message\ServerRequestInterface;

// everysize-Klassen liegen unter src/ — der eigene PSR-4-Autoloader ist
// idempotent und wird zusätzlich von den Admin-Tab-Dateien und CLI-Commands
// eingebunden.
require_once __DIR__ . '/src/autoload.php';

/**
 * Bootstrapper of the everysize checkout plugin.
 */
class Bootstrap extends Bootstrapper
{
    /** tcron jobType of the fallback cron job (runs the sync when no cron script reaches the CLI). */
    public const CRON_TYPE = SyncCronJob::TYPE;

    /** Route of the webcron endpoint (token-protected). */
    public const WEBCRON_PATH = '/everysize-checkout/cron';

    public function boot(Dispatcher $dispatcher): void
    {
        parent::boot($dispatcher);

        // JTL cron: map the jobType of tcron rows to the plugin's job class,
        // and offer the job in the cron admin's "new job" list.
        $dispatcher->listen(Event::MAP_CRONJOB_TYPE, static function (array &$args): void {
            if ($args['type'] === self::CRON_TYPE) {
                $args['mapping'] = SyncCronJob::class;
            }
        });
        $dispatcher->listen(Event::GET_AVAILABLE_CRONJOBS, static function (array &$args): void {
            $args['jobs'][] = SyncCronJob::TYPE;
        });

        // Webcron: GET/POST /everysize-checkout/cron — token via header
        // X-Everysize-Token (query parameter as fallback; the header keeps
        // the token out of access logs).
        if (\defined('HOOK_ROUTER_PRE_DISPATCH')) {
            $dispatcher->listen('shop.hook.' . \HOOK_ROUTER_PRE_DISPATCH, static function (array $args): void {
                /** @var Router $router */
                $router = $args['router'];
                $router->addRoute(self::WEBCRON_PATH, static function (ServerRequestInterface $request, array $routeArgs, $smarty): TextResponse {
                    return self::handleWebcron($request);
                }, 'everysize_checkout_cron', ['GET', 'POST']);
            });
        }
    }

    public function installed(): void
    {
        parent::installed();

        // Token for the webcron URL, generated once so the URL is stable.
        $settings = new Settings(new JtlConnection($this->getDB()));
        if ($settings->get(Settings::WEBCRON_TOKEN) === '') {
            $settings->set(Settings::WEBCRON_TOKEN, \bin2hex(\random_bytes(16)));
        }

        // Auto-refund anchored at install/update time if it has no anchor
        // yet — the settings page sets the anchor when the option is turned
        // on; this catches a merchant who installs with the default (on).
        if ($settings->get(Settings::AUTO_REFUND_SINCE) === '') {
            $settings->set(Settings::AUTO_REFUND_SINCE, date('Y-m-d H:i:s'));
        }

        // The fallback cron job fires on every cron tick (frequency 0); the
        // job itself throttles to one sync per five minutes.
        $existing = $this->getDB()->select('tcron', 'jobType', self::CRON_TYPE);
        if ($existing === null || (int) ($existing->cronID ?? 0) === 0) {
            $this->getDB()->insert('tcron', (object) [
                'name' => 'everysize checkout sync',
                'jobType' => self::CRON_TYPE,
                'frequency' => 0,
                'startDate' => 'NOW()',
                'startTime' => '00:00:00',
            ]);
        }
    }

    public function uninstalled(bool $deleteData = true): void
    {
        parent::uninstalled($deleteData);

        // The tcron row is plugin-owned (JTL does not remove it) — its queue
        // entry too, otherwise a stuck isRunning row would survive the
        // uninstall. The xplugin_* tables are dropped by the migration's
        // down() (unless the merchant kept the data).
        $existing = $this->getDB()->select('tcron', 'jobType', self::CRON_TYPE);
        $cronId = (int) ($existing->cronID ?? 0);
        if ($cronId > 0) {
            $this->getDB()->delete('tjobqueue', 'cronID', $cronId);
        }
        $this->getDB()->delete('tcron', 'jobType', self::CRON_TYPE);
    }

    /**
     * Webcron handler: token check, then one sync run. No lock file, the
     * sync takes a MySQL GET_LOCK itself.
     */
    private static function handleWebcron(ServerRequestInterface $request): TextResponse
    {
        $db = Shop::Container()->getDB();
        $connection = new JtlConnection($db);
        $settings = new Settings($connection, 0);
        // Header first, query parameter as fallback (documented for simple
        // cron systems that cannot send headers).
        $token = (string) ($request->getHeaderLine('X-Everysize-Token') ?: ($request->getQueryParams()['token'] ?? ''));
        if ($token === '' || !\hash_equals((string) $settings->get(Settings::WEBCRON_TOKEN, ''), $token)) {
            return new TextResponse("invalid token\n", 403);
        }

        $pluginId = (int) ($db->select('tplugin', 'cPluginID', PluginInfo::ID)->kPlugin ?? 0);
        $result = Sync::create($db, $pluginId)->run();
        $lines = array_merge($result['messages'], array_map(static fn (string $error): string => 'ERROR: ' . $error, $result['errors']));

        return new TextResponse(implode("\n", $lines) . "\n", $result['errors'] === [] ? 200 : 500);
    }
}
