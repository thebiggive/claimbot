<?php

declare(strict_types=1);

namespace ClaimBot\Messenger\Handler;

use ClaimBot\Claimer;
use ClaimBot\Exception\ClaimException;
use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Messenger\OutboundMessageBus;
use ClaimBot\Settings\SettingsInterface;
use Messages\Donation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * @link https://symfony.com/blog/new-in-symfony-5-4-messenger-improvements#handle-messages-in-batches
 */
class ClaimableDonationHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    /**
     * @var int Set once based on SQS message count or fallback maximum batch size, at initial run time. No need to
     *          re-check on every {@see ClaimableDonationHandler::shouldFlush()} check.
     */
    private int $batchSize;

    public function __construct(
        private Claimer $claimer,
        private LoggerInterface $logger,
        private OutboundMessageBus $bus,
        SettingsInterface $settings,
    ) {
        $this->batchSize = $settings->get('current_batch_size');
    }

    public function __invoke(Donation $message, Acknowledger $ack = null)
    {
        $this->logger->info(sprintf('Received message for Donation ID %s', $message->id));

        return $this->handle($message, $ack);
    }

    private function process(array $jobs): void
    {
        // Both keyed on donation ID.
        $acks = [];
        $donations = [];

        foreach ($jobs as [$message, $ack]) {
            $acks[$message->id] = $ack;
            $donations[$message->id] = $message;
        }

        try {
            $this->claimer->claim($donations);

            // Success!
            foreach ($acks as $ack) {
                $ack->ack(true);
            }

            $this->logger->info(sprintf(
                'Claim succeeded and all %d donation messages acknowledged',
                count($donations),
            ));
        } catch (DonationDataErrorsException $donationDataErrorsException) {
            foreach (array_keys($donationDataErrorsException->getDonationErrors()) as $donationId) {
                $this->logger->notice(sprintf(
                    'Claim failed with donation-specific errors; sending %s to failure queue',
                    $donationId,
                ));

                $this->sendToErrorQueue($donations[$donationId]); // Let MatchBot record that there's an error.

                // Don't keep re-trying the donation – ack it to the inbound ClaimBot queue.
                $acks[$donationId]->ack(false);
            }

            $donationsToRetry = $this->claimer->getRemainingValidDonations();
            if (count($donationsToRetry) === 0) {
                return;
            }

            $this->logger->info(sprintf('Retrying %d remaining donations without errors...', count($donationsToRetry)));

            try {
                $this->claimer->claim($donationsToRetry);

                // Success – for the remainder!
                foreach ($donationsToRetry as $donationId => $donation) {
                    $acks[$donationId]->ack(true);
                }

                $this->logger->info(sprintf(
                    'Re-tried claim succeeded and %d donation messages acknowledged',
                    count($donationsToRetry),
                ));
            } catch (ClaimException $retryException) {
                $this->logger->error('Re-tried claim failed too. No more error detection.');

                foreach ($donationsToRetry as $donationId => $donation) {
                    // Something unexpected is going on – probably most helpful to send all impacted
                    // donations to the error queue so they can be easily investigated in MatchBot
                    // DB.
                    $this->sendToErrorQueue($donation);
                    $acks[$donationId]->nack($retryException);
                }
            }
        } catch (ClaimException $exception) {
            // There is some other error – potentially an internal problem rather than one with donation data.
            // nack() all claim messages.

            $this->logger->notice('Claim failed with general errors');

            foreach ($acks as $ack) {
                $ack->nack($exception);
            }
        }
    }

    private function sendToErrorQueue(Donation $donation): bool
    {
        $stamps = [
            new BusNameStamp('claimbot.donation.error'),
            new TransportMessageIdStamp("claimbot.donation.error.{$donation->id}"),
        ];

        try {
            $this->bus->dispatch(new Envelope($donation, $stamps));
        } catch (TransportException $exception) {
            $this->logger->error(sprintf(
                'claimbot.donation.error queue dispatch error %s. Donation ID %s.',
                $exception->getMessage(),
                $donation->id,
            ));

            return false;
        }

        return true;
    }

    private function shouldFlush(): bool
    {
        $this->logger->debug(sprintf(
            "Checking whether to flush with %d batch size and %d jobs",
            $this->batchSize,
            \count($this->jobs),
        ));

        return $this->batchSize <= \count($this->jobs);
    }
}
