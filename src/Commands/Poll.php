<?php

declare(strict_types=1);

namespace ClaimBot\Commands;

use ClaimBot\Claimer;
use ClaimBot\Exception\ClaimException;
use ClaimBot\Settings\SettingsInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Send applicable donations to ClaimBot for HMRC Gift Aid claims.
 */
class Poll extends Command
{
    protected static $defaultName = 'claimbot:poll';

    public function __construct(
        private Claimer $claimer,
        private LoggerInterface $logger,
        private SettingsInterface $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Polls for status of a request with the given correlation ID');
        $this->addArgument(
            'correlationId',
            InputArgument::REQUIRED,
            'The correlation ID returned in the acknowledgement of a Gift Aid claim',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->getName() . ' starting!');

        try {
            $polledOK = $this->claimer->pollForResponse(
                $input->getArgument('correlationId'),
                $this->claimer->getDefaultPollUrl($this->settings->get('environment') !== 'production'),
            );
        } catch (ClaimException $claimException) {
            $this->logger->error(sprintf(
                'Claim failed with %s: %s',
                get_class($claimException),
                $claimException->getMessage(),
            ));

            return 20;
        } catch (\Throwable $generalException) {
            $this->logger->error(sprintf(
                'Unexpected %s: %s',
                get_class($generalException),
                $generalException->getMessage(),
            ));

            return 30;
        }

        if ($polledOK) {
            $output->writeln($this->getName() . ' – Poll success.');
            $output->writeln(sprintf(
                $this->getName() . ' – Full HMRC XML response for correlation ID %s: %s',
                $input->getArgument('correlationId'),
                $this->claimer->getLastResponseMessage(),
            ));
        }

        $output->writeln($this->getName() . ' complete!');

        return $polledOK ? 0 : 10;
    }
}
