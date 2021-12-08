<?php

declare(strict_types=1);

namespace ClaimBot\Exception;

class DonationDataErrorsException extends HMRCRejectionException
{
    /** @var array  2D, keyed on donation ID. Values are arrays with 'text' and 'location' with XML error details. */
    protected array $donationErrors;

    public function __construct(array $donationsErrors, string $allErrorMessages)
    {
        parent::__construct($allErrorMessages);
        $this->setDonationErrors($donationsErrors);
    }

    /**
     * @return array
     */
    public function getDonationErrors(): array
    {
        return $this->donationErrors;
    }

    /**
     * @param array $donationErrors
     */
    public function setDonationErrors(array $donationErrors): void
    {
        $this->donationErrors = $donationErrors;
    }
}
