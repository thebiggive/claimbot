<?php

declare(strict_types=1);

namespace ClaimBot;

use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Exception\HMRCRejectionException;
use ClaimBot\Exception\UnexpectedResponseException;
use DateTime;
use GovTalk\GiftAid\ClaimingOrganisation;
use GovTalk\GiftAid\GiftAid;
use Messages\Donation;
use Psr\Log\LoggerInterface;

class Claimer
{
    /** @var array Assoc on donation ID. Populated on errors so remaining donations can be accessed for retries. */
    private array $remainingValidDonations = [];

    private ?string $lastCorrelationId = null;

    private ?string $lastResponseMessage = null;

    /**
     * @var string[]    Keyed on donation ID.
     */
    private array $donationErrorMessages = [];

    public function __construct(private GiftAid $giftAid, private LoggerInterface $logger)
    {
    }

    /**
     * @param Donation[] $donations         Associative, keyed on donation ID. All with same 'org_hmrc_ref'.
     * @return bool                         True if the submission succeeded.
     * @throws DonationDataErrorsException  if at least one donation-specific error was returned
     * @throws HMRCRejectionException       if there is otherwise a specific XML format error from HMRC
     * @throws UnexpectedResponseException  if there was no successful correlation ID but neither of the above error
     *                                      cases was observed.
     */
    public function claim(array $donations): bool
    {
        $this->giftAid->clearClaimingOrganisations();
        $orgHMRCRefsAdded = [];

        /** @var ?DateTime $claimToDate */
        $claimToDate = null;

        $plainArrayDonations = [];
        foreach ($donations as $donation) {
            $plainArrayDonations[] = (array) $donation;

            if (!in_array($donation->org_hmrc_ref, $orgHMRCRefsAdded, true)) {
                $this->giftAid->addClaimingOrganisation(new ClaimingOrganisation(
                    $donation->org_name,
                    $donation->org_hmrc_ref,
                    $donation->org_regulator,
                    $donation->org_regulator_number,
                ));
                $orgHMRCRefsAdded[] = $donation->org_hmrc_ref;
            }

            if ($claimToDate === null || new \DateTime($donation->donation_date) > $claimToDate) {
                $claimToDate = new \DateTime($donation->donation_date);
            }
        }

        // Must be date of most recent donation in the current claim.
        $this->giftAid->setClaimToDate($claimToDate->format('Y-m-d'));

        $claimOutcome = $this->giftAid->giftAidSubmit($plainArrayDonations);

        if (!empty($claimOutcome['correlationid'])) {
            $this->remainingValidDonations = $donations;
            $this->lastCorrelationId = $claimOutcome['correlationid'];

            $this->logger->info(sprintf('Claim acknowledged. Correlation ID %s', $this->lastCorrelationId));

            $pollDetails = $this->giftAid->getResponseEndpoint();
            $pollUrl = $pollDetails['endpoint'];
            $pollInterval = (int) $pollDetails['interval'];

            return $this->pollForResponse($this->lastCorrelationId, $pollUrl, $pollInterval);
        }

        if (empty($claimOutcome['errors'])) {
            $this->logger->error('Neither correlation ID nor errors. Is the endpoint valid?');

            throw new UnexpectedResponseException('Response had neither correlation ID nor errors');
        }

        $this->handleErrors($claimOutcome['errors']);
    }

    /**
     * @return Donation[]   Associative, keyed on donation ID.
     */
    public function getRemainingValidDonations(): array
    {
        return $this->remainingValidDonations;
    }

    public function getLastCorrelationId(): ?string
    {
        return $this->lastCorrelationId;
    }

    public function getLastResponseMessage(): ?string
    {
        return $this->lastResponseMessage;
    }

    /**
     * Get summarised error message for the given donation ID, if any.
     */
    public function getDonationError(string $donationId): ?string
    {
        return $this->donationErrorMessages[$donationId] ?? null;
    }

    public function pollForResponse(string $correlationId, string $pollUrl, int $pollInterval = 1): bool
    {
        $maxSecondsToPoll = 45;
        $startTime = microtime(true);
        $pollInterval = max($pollInterval, 1); // Always 1+ seconds between iterations.

        while ((microtime(true) - $startTime) < $maxSecondsToPoll) { // And while nothing has `return`ed.
            sleep($pollInterval);
            $this->logger->info(sprintf(
                'Sending poll request to %s for correlation ID %s – slept %ds as instructed',
                $pollUrl,
                $correlationId,
                $pollInterval,
            ));
            $claimOutcome = $this->giftAid->declarationResponsePoll($correlationId, $pollUrl);
            $qualifier = $this->giftAid->getResponseQualifier();

            if ($qualifier !== 'response' && $qualifier !== 'error') {
                $this->logger->debug(sprintf('No response yet (%s), looping...', $qualifier));
                continue;
            }

            if (!empty($claimOutcome['errors'])) {
                $this->handleErrors($claimOutcome['errors']);
            } else {
                $this->lastResponseMessage = $claimOutcome['submission_response_message'] ?? '[None]';
                $this->remainingValidDonations = [];
            }

            return true;
        }

        $this->logger->error(sprintf('No poll response after %d seconds', $maxSecondsToPoll));
        return false;
    }

    public function getDefaultPollUrl(bool $testMode): string
    {
        return str_replace(
            '/submission',
            '/poll',
            $this->giftAid->getClaimEndpoint(),
        );
    }

    private function handleErrors(array $errors): void
    {
        // $claimOutcome['errors'] – passed in here as $errors – is a 3D array:
        // top level keys: 'fatal', 'recoverable', 'business', 'warning'.
        // 2nd level when 'business' errors encountered was numeric-indexed starting at 1.
        // 3rd level inside 'business' error items had keys 'number', 'text' and 'location' – where 'text' was
        //   a human-readable, helpful error message and 'location' an XPath locator. Not sure what 'number' means.

        $failedDonationErrors = [];
        // Make a copy so we can remove donation-particular errors just from this var.
        $nonDonationMappedErrors = $errors;

        if (!empty($errors['business'])) {
            foreach ($errors['business'] as $key => $error) {
                if (!empty($error['donation_id'])) {
                    unset($this->remainingValidDonations[$error['donation_id']]);
                    $failedDonationErrors[$error['donation_id']] = $error;
                    $this->logger->error(sprintf(
                        'Donation ID %s error at %s [%s]: %s',
                        $error['donation_id'],
                        $error['location'],
                        $error['message'],
                        $error['text'],
                    ));
                    $this->donationErrorMessages[$error['donation_id']] = $error['text'];
                    unset($nonDonationMappedErrors['business'][$key]);
                }
            }
        }

        if (empty($nonDonationMappedErrors['business'])) { // i.e. no errors without donation IDs remain.
            unset($nonDonationMappedErrors['business']);
        }

        // Log remaining errors.
        $this->logger->debug('Remaining errors: ' . print_r($nonDonationMappedErrors, true));

        if (!empty($failedDonationErrors)) {
            $exception = new DonationDataErrorsException($failedDonationErrors);
        } elseif (!empty($errors['fatal'])) {
            $exception = new HMRCRejectionException('Fatal: ' . $errors['fatal'][0]['text']);
        } else {
            $exception = new HMRCRejectionException('HMRC submission errors');
        }

        $exception->setRawHMRCErrors($errors);

        throw $exception;
    }
}
