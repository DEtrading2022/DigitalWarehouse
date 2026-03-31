<?php

declare(strict_types=1);

namespace DigitalWarehouse\Wock\Console\Command;

use DigitalWarehouse\Wock\Api\PartnerServiceInterface;
use DigitalWarehouse\Wock\Api\TokenManagerInterface;
use DigitalWarehouse\Wock\Exception\ApiException;
use DigitalWarehouse\Wock\Exception\AuthenticationException;
use DigitalWarehouse\Wock\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento wock:test:connection
 *
 * Verifies:
 *   1. Module is enabled
 *   2. Azure AD token can be acquired
 *   3. WoCK partner query succeeds (proves endpoint + auth)
 */
class TestConnection extends Command
{
    public function __construct(
        private readonly Config                 $config,
        private readonly TokenManagerInterface  $tokenManager,
        private readonly PartnerServiceInterface $partnerService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('wock:test:connection')
             ->setDescription('Test WoCK API connectivity and credentials');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<info>WoCK Connection Test</info>');
        $output->writeln(str_repeat('─', 50));

        // 1. Module status
        $enabled = $this->config->isEnabled() ? '<info>Enabled</info>' : '<comment>Disabled (module will still be tested)</comment>';
        $output->writeln("Module status : $enabled");
        $output->writeln('Environment   : ' . $this->config->getEnvironment());
        $output->writeln('Endpoint      : ' . $this->config->getGraphQlEndpoint());
        $output->writeln('');

        // 2. Acquire token
        $output->write('1. Acquiring Azure AD token ... ');
        try {
            $token = $this->tokenManager->refreshToken();
            $output->writeln('<info>OK</info> (length: ' . strlen($token) . ')');
        } catch (AuthenticationException $e) {
            $output->writeln('<e>FAILED</e>');
            $output->writeln('<e>   ' . $e->getMessage() . '</e>');
            return Command::FAILURE;
        }

        // 3. Partner query
        $output->write('2. Calling partner query        ... ');
        try {
            $partner = $this->partnerService->getPartner();
            $output->writeln('<info>OK</info>');
            $output->writeln('');
            $output->writeln('<info>Partner details:</info>');
            $output->writeln('  ID           : ' . ($partner['id'] ?? 'n/a'));
            $output->writeln('  Type         : ' . ($partner['partnerType'] ?? 'n/a'));
            $output->writeln('  Available    : ' . ($partner['available'] ?? 'n/a'));
            $output->writeln('  Used         : ' . ($partner['used'] ?? 'n/a'));
            $output->writeln('  Stock limit  : ' . ($partner['stockLimit'] ?? 'n/a'));
        } catch (ApiException $e) {
            $output->writeln('<e>FAILED</e>');
            $output->writeln('<e>   ' . $e->getMessage() . '</e>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln(str_repeat('─', 50));
        $output->writeln('<info>All tests passed. WoCK API is reachable.</info>');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
