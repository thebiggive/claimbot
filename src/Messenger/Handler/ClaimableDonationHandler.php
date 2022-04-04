<?php

declare(strict_types=1);

namespace ClaimBot\Messenger\Handler;

use Brick\Postcode\InvalidPostcodeException;
use Brick\Postcode\PostcodeFormatter;
use ClaimBot\Claimer;
use ClaimBot\Exception\ClaimException;
use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Messenger\OutboundMessageBus;
use ClaimBot\Settings\SettingsInterface;
use Messages\Donation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
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
        private PostcodeFormatter $postcodeFormatter,
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

        foreach ($jobs as [$donation, $ack]) {
            /** @var Donation $donation */
            $acks[$donation->id] = $ack;

            try {
                $formattedDonation = $this->format($donation);

                // Only reached if `format()` didn't throw an exception – if it did the batch for HMRC excludes this
                // donation and we continue the `foreach` loop.
                $donations[$donation->id] = $formattedDonation;
            } catch (InvalidPostcodeException $exception) {
                $this->logger->warning(sprintf(
                    'Could not reformat invalid postcode %s; sending %s to result queue as failed and not to HMRC.',
                    $donation->postcode,
                    $donation->id // e.g. Donation UUID.
                ));
                // Let MatchBot record that there's a failure. Note that in this failed validation
                // case we set responseSuccess false even though there is no correlation ID,
                // because we know it's likely not worth sending this bad data to HMRC.
                $donation->responseSuccess = false;
                $this->sendToResultQueue($donation);

                // Don't keep re-trying the donation – ack it to the inbound ClaimBot queue.
                $acks[$donation->id]->ack(false);
                unset($acks[$donation->id]); // $donations never has the badly formatted donation added.
            }
        }

        try {
            $outcome = $this->claimer->claim($donations);
            // We assume success even if the poll is slow, but warning log that case below.
            $this->markSuccessful($donations, $acks);

            if ($outcome) {
                $this->logger->info(sprintf(
                    'Claim sent and %d donation messages acknowledged',
                    // The number sent to HMRC, not necessarily the number we started with.
                    count($donations),
                ));
            } else {
                $this->logger->warning(sprintf(
                    "Claim sent and %d donation messages ack'd, but poll timed out",
                    count($donations),
                ));
            }
        } catch (DonationDataErrorsException $donationDataErrorsException) {
            foreach (array_keys($donationDataErrorsException->getDonationErrors()) as $donationId) {
                $this->logger->notice(sprintf(
                    'Claim failed with donation-specific errors; sending %s to result queue as failed',
                    $donationId,
                ));

                $donations[$donationId]->responseSuccess = false;
                $donations[$donationId]->responseDetail = $this->claimer->getDonationError($donationId);
                $donations[$donationId]->submissionCorrelationId = $this->claimer->getLastCorrelationId();
                $this->sendToResultQueue($donations[$donationId]); // Let MatchBot record that there's an error.

                // Don't keep re-trying the donation – ack it to the inbound ClaimBot queue.
                $acks[$donationId]->ack(false);
                unset($acks[$donationId]);
            }

            $donationsToRetry = $this->claimer->getRemainingValidDonations();
            if (count($donationsToRetry) === 0) {
                return;
            }

            $this->logger->info(sprintf('Retrying %d remaining donations without errors...', count($donationsToRetry)));

            try {
                $this->claimer->claim($donationsToRetry);

                // Success – for the remainder!
                $this->markSuccessful($donationsToRetry, $acks);

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
                    $donation->responseSuccess = false;
                    $this->sendToResultQueue($donation);
                    $acks[$donationId]->nack($retryException);
                }
            }
        } catch (\Throwable $exception) {
            // There is some other error – potentially an internal problem rather than one with donation data
            // -> nack() all claim messages.
            // Remaining exceptions *should* be ClaimException subclasses, but catch
            // anything to be safe.
            $this->logger->error(sprintf(
                'Claim failed with unexpected %s: %s',
                get_class($exception),
                $exception->getMessage(),
            ));

            foreach ($acks as $ack) {
                $ack->nack($exception);
            }
        }
    }

    private function sendToResultQueue(Donation $donation): bool
    {
        $stamps = [
            new BusNameStamp('claimbot.donation.result'),
            new TransportMessageIdStamp("claimbot.donation.result.{$donation->id}"),
        ];

        try {
            $this->bus->dispatch(new Envelope($donation, $stamps));
        } catch (\Throwable $exception) {
            // We only *expect* Symfony\Component\Messenger\Exception\TransportException
            // + subclasses here, but it's safer to catch everything to ensure unexpected
            // issues can't derail the whole command run.
            $this->logger->error(sprintf(
                'claimbot.donation.result queue dispatch error %s: %s. Donation ID %s.',
                get_class($exception),
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

    /**
     * @param Donation $donation
     * @return Donation
     * @throws InvalidPostcodeException if postcode is just in the wrong format and can't be tidied according to the
     *                                  Brick lib.
     */
    private function format(Donation $donation): Donation
    {
        if (!empty($donation->postcode)) {
            // MatchBot sets postcode to blank string if the donor has said they're overseas in the context of
            // the Gift Aid / home address input. HMRC does not expect/allow any zip when we say the donor lives
            // outside the UK.
            $donation->postcode = (new PostcodeFormatter())->format('GB', $donation->postcode);
        }

        // We've seen HMRC reject claims with lowercase letters.
        $donation->org_hmrc_ref = strtoupper($donation->org_hmrc_ref);

        return $donation;
    }

    /**
     * Sends success data to result queue, and positive ack's to original claims queue.
     *
     * @param array $donations      Keyed on donation ID
     * @param array $acknowledgers  Keyed on donation ID
     */
    private function markSuccessful(array $donations, array $acknowledgers): void
    {
        $correlationId = $this->claimer->getLastCorrelationId();
        $responseMessage = $this->claimer->getLastResponseMessage();

        foreach ($donations as $donationId => $donation) {
            $donation->submissionCorrelationId = $correlationId;
            $donation->responseSuccess = true;
            $donation->responseDetail = $responseMessage;

            $this->sendToResultQueue($donation);

            try {
                $acknowledgers[$donationId]->ack(true);
            } catch (\Throwable $exception) {
                $this->logger->error(sprintf(
                    'Success ack for donation %s failed with %s: %s',
                    $donationId,
                    get_class($exception),
                    $exception->getMessage(),
                ));
            }
        }
    }
}
