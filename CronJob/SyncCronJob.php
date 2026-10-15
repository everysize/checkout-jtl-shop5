<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\CronJob;

require_once __DIR__ . '/../src/autoload.php';

use JTL\Cron\Job;
use JTL\Cron\JobInterface;
use JTL\Cron\QueueEntry;
use Plugin\everysize_checkout\PluginInfo;
use Plugin\everysize_checkout\Settings;
use Plugin\everysize_checkout\Sync;

/**
 * The fallback cron job: runs the import/export every time it fires
 * (tcron frequency = 0, each cron tick) but throttles itself to one sync
 * per five minutes — the webcron URL is the intended trigger, this job
 * catches up if it has not been called for a while.
 */
final class SyncCronJob extends Job
{
    public const TYPE = 'everysize_checkout_sync';

    private const THROTTLE_SECONDS = 300;

    /**
     * @param QueueEntry $queueEntry
     */
    public function start($queueEntry): JobInterface
    {
        parent::start($queueEntry);

        try {
            $settings = new Settings(new \Plugin\everysize_checkout\Db\JtlConnection($this->db), 0);
            $lastSync = (string) $settings->get(Settings::LAST_SYNC, '');
            if ($lastSync !== '' && (time() - (int) strtotime($lastSync)) < self::THROTTLE_SECONDS) {
                $this->logger->info('everysize checkout: last sync is less than 5 minutes ago, skipping');
            } else {
                $pluginId = (int) ($this->db->select('tplugin', 'cPluginID', PluginInfo::ID)->kPlugin ?? 0);
                $result = Sync::create($this->db, $pluginId)->run();
                foreach ($result['messages'] as $message) {
                    $this->logger->info('everysize checkout: {message}', ['message' => $message]);
                }
                foreach ($result['errors'] as $error) {
                    $this->logger->error('everysize checkout: {error}', ['error' => $error]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('everysize checkout: sync failed: {error}', ['error' => $e->getMessage()]);
        }

        // Job::setFinished() returns void — returning its result instead of
        // the job itself is a TypeError in strict mode and leaves the queue
        // entry stuck on isRunning = 1 (JTL re-enqueues the job only after
        // the entry is finished).
        $this->setFinished(true);

        return $this;
    }
}
