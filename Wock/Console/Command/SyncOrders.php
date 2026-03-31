<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Console\Command;

use DigitalWarehouse\Wock\Cron\SyncOrders as SyncOrdersCron;
use DigitalWarehouse\Wock\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento wock:sync:orders
 */
class SyncOrders extends Command
{
    public function __construct(
        private readonly SyncOrdersCron $syncOrdersCron,
        private readonly Config         $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('wock:sync:orders')
             ->setDescription('Poll WoCK for ready orders and process delivery keys');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<e>WoCK module is disabled. Enable it in Stores > Configuration.</e>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Starting WoCK order sync...</info>');

        try {
            $this->syncOrdersCron->execute();
            $output->writeln('<info>Order sync completed successfully.</info>');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<e>Order sync failed: ' . $e->getMessage() . '</e>');
            return Command::FAILURE;
        }
    }
}
