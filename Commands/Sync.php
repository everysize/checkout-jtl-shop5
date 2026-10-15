<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Commands;

require_once __DIR__ . '/../src/autoload.php';

use JTL\Console\Command\Command;
use Plugin\everysize_checkout\PluginInfo;
use Plugin\everysize_checkout\Sync as EverysizeSync;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * everysize_checkout:sync — runs one import/export cycle. The webcron URL is
 * the intended trigger for the running shop; this command is the manual and
 * system-cron fallback (no boot() runs in the CLI, so the src autoloader is
 * required above).
 */
final class Sync extends Command
{
    protected function configure(): void
    {
        $this->setName('sync')
            ->setDescription('everysize checkout: import orders and report shipping/cancellations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runSync(true, true);
    }

    private function runSync(bool $import, bool $report): int
    {
        $db = $this->getDB();
        $pluginId = (int) ($db->select('tplugin', 'cPluginID', PluginInfo::ID)->kPlugin ?? 0);
        $result = EverysizeSync::create($db, $pluginId)->run($import, $report);
        foreach ($result['messages'] as $message) {
            $this->getIO()->writeln($message);
        }
        foreach ($result['errors'] as $error) {
            $this->getIO()->error($error);
        }

        return $result['errors'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
