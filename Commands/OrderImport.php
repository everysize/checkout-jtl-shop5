<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Commands;

require_once __DIR__ . '/../src/autoload.php';

use JTL\Console\Command\Command;
use Plugin\everysize_checkout\PluginInfo;
use Plugin\everysize_checkout\Sync;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * everysize_checkout:order:import — only imports new everysize orders (and
 * reports their order numbers), does not report shipping/cancellations.
 */
final class OrderImport extends Command
{
    protected function configure(): void
    {
        $this->setName('order:import')
            ->setDescription('everysize checkout: only import new orders, no reporting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->getDB();
        $pluginId = (int) ($db->select('tplugin', 'cPluginID', PluginInfo::ID)->kPlugin ?? 0);
        $result = Sync::create($db, $pluginId)->run(true, false);
        foreach ($result['messages'] as $message) {
            $this->getIO()->writeln($message);
        }
        foreach ($result['errors'] as $error) {
            $this->getIO()->error($error);
        }

        return $result['errors'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
