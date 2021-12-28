<?php

declare(strict_types=1);

namespace ClaimBot;

use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Exception\HMRCRejectionException;
use ClaimBot\Exception\UnexpectedResponseException;
use ClaimBot\Messenger\Donation;
use GovTalk\GiftAid\GiftAid;
use Psr\Log\LoggerInterface;

class Claimer
{
    public function __construct(private GiftAid $giftAid, private LoggerInterface $logger)
    {
    }

    /**
     * @param Donation[] $donations
     * @return bool True if the submission succeeded.
     * @throws DonationDataErrorsException  if at least one donation-specific error was returned
     * @throws HMRCRejectionException       if there is otherwise a specific XML format error from HMRC
     * @throws UnexpectedResponseException  if there was no successful correlation ID but neither of the above error
     *                                      cases was observed.
     */
    public function claim(array $donations): bool
    {
        $plainArrayDonations = [];
        foreach ($donations as $donation) {
            $plainArrayDonations[] = (array) $donation;
        }

        $claimOutcome = $this->giftAid->giftAidSubmit($plainArrayDonations);

        if (!empty($claimOutcome['correlationid'])) {
            $this->logger->info(sprintf('Claim succeeded. Correlation ID %s', $claimOutcome['correlationid']));

            return true;
        }

        if (empty($claimOutcome['errors'])) {
            $this->logger->error('Neither correlation ID nor errors. Is the endpoint valid?');

            throw new UnexpectedResponseException();
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
}
