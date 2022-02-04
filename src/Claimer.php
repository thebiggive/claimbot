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

    public function __construct(private GiftAid $giftAid, private LoggerInterface $logger)
    {
    }

    /**
     * @param Donation[] $donations         Associactive, keyed on donation ID
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
            $this->logger->info(sprintf('Claim succeeded. Correlation ID %s', $claimOutcome['correlationid']));

            return true;
        }

        // Copy (unmodified) assoc array with a new name to clarify its use in the rest of the method.
        $this->remainingValidDonations = $donations;

        if (empty($claimOutcome['errors'])) {
            $this->logger->error('Neither correlation ID nor errors. Is the endpoint valid?');

            throw new UnexpectedResponseException('Response had neither correlation ID nor errors');
        }

        // $response['errors'] is a 3D array:
        // top level keys: 'fatal', 'recoverable', 'business', 'warning'.
        // 2nd level when 'business' errors encountered was numeric-indexed starting at 1.
        // 3rd level inside 'business' error items had keys 'number', 'text' and 'location' – where 'text' was
        //   a human-readable, helpful error message and 'location' an XPath locator. Not sure what 'number' means.

        $failedDonationErrors = [];
        // Make a copy so we can remove donation-particular errors just from this var.
        $nonDonationMappedErrors = $claimOutcome['errors'];

        if (!empty($claimOutcome['errors']['business'])) {
            foreach ($claimOutcome['errors']['business'] as $key => $error) {
                if (!empty($error['donation_id'])) {
                    unset($this->remainingValidDonations[$error['donation_id']]);
                    $failedDonationErrors[$error['donation_id']] = $error;
                    $this->logger->error(sprintf(
                        'Donation ID %s error at %s: %s',
                        $error['donation_id'],
                        $error['location'],
                        $error['text'],
                    ));
                    unset($nonDonationMappedErrors['business'][$key]);
                }
            }
        }

        if (empty($nonDonationMappedErrors['business'])) { // i.e. no errors without donation IDs remain.
            unset($nonDonationMappedErrors['business']);
        }

        // Log remaining errors.
        $this->logger->error('Remaining errors: ' . print_r($nonDonationMappedErrors, true));

        if (!empty($failedDonationErrors)) {
            $exception = new DonationDataErrorsException($failedDonationErrors);
        } elseif (!empty($claimOutcome['errors']['fatal'])) {
            $exception = new HMRCRejectionException('Fatal: ' . $claimOutcome['errors']['fatal'][0]['text']);
        } else {
            $exception = new HMRCRejectionException('HMRC submission errors');
        }

        $exception->setRawHMRCErrors($claimOutcome['errors']);

        throw $exception;
    }

    /**
     * @return Donation[]   Associative, keyed on donation ID.
     */
    public function getRemainingValidDonations(): array
    {
        return $this->remainingValidDonations;
    }
}
