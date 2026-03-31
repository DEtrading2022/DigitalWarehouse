<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Console\Command;

use DigitalWarehouse\Wock\Cron\SyncProducts as SyncProductsCron;
use DigitalWarehouse\Wock\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento wock:sync:products [--full]
 */
class SyncProducts extends Command
{
    public function __construct(
        private readonly SyncProductsCron $syncProductsCron,
        private readonly Config          $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('wock:sync:products')
             ->setDescription('Synchronise WoCK products with Magento catalogue')
             ->addOption(
                 'full',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force a full sync (ignore last-sync timestamp)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>WoCK module is disabled. Enable it in Stores > Configuration.</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('full')) {
            // Clear last-sync timestamp via the cron class
            $this->syncProductsCron->clearLastSync();
            $output->writeln('<info>Last-sync timestamp cleared — running full sync.</info>');
        }

        $output->writeln('<info>Starting WoCK product sync...</info>');

        try {
            $this->syncProductsCron->execute();
            $output->writeln('<info>Product sync completed successfully.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Product sync failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
