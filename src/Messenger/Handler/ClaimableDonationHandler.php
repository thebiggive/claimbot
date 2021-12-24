<?php

declare(strict_types=1);

namespace ClaimBot\Messenger\Handler;

use ClaimBot\Claimer;
use ClaimBot\Exception\ClaimException;
use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Messenger\Donation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * @link https://symfony.com/blog/new-in-symfony-5-4-messenger-improvements#handle-messages-in-batches
 */
class ClaimableDonationHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    private static int $donationsPerClaim = 50;

    public function __construct(
        private Claimer $claimer,
        private LoggerInterface $logger,
        private RoutableMessageBus $bus,
    ) {
    }

    public function __invoke(Donation $message, Acknowledger $ack = null)
    {
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
        } catch (DonationDataErrorsException $donationDataErrorsException) {
            // todo check this is also error logged with more detail, with current approach.

            foreach (array_keys($donationDataErrorsException->getDonationErrors()) as $donationId) {
                $this->sendToErrorQueue($donations[$donationId]); // Let MatchBot record that there's an error.

                $acks[$donationId]->ack(false); // Don't keep re-trying the claim – ack it to the original claim queue.
            }
        } catch (ClaimException $exception) {
            // There is some other error – potentially an internal problem rather than one with donation data.
            // nack() all claim messages so they are enqueued for a retry on next run.
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
        return static::$donationsPerClaim <= \count($this->jobs);
    }
}
