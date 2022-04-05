<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Exception;

use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Tests\TestCase;

class DonationDataErrorsExceptionTest extends TestCase
{
    private DonationDataErrorsException $exception;

    public function setUp(): void
    {
        $donationErrors = [
            'idA' => [
                'donation_id' => 'idA',
                'message' => "cvc-type.3.1.3: The value '' of element 'Sur' is not valid.",
                'text' => "Invalid content found at element 'Sur'",
                'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                    'r68:Claim[1]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
            ],
            'idB' => [
                'donation_id' => 'idB',
                'message' => "cvc-type.3.1.3: The value '' of element 'Fore' is not valid.",
                'text' => "Invalid content found at element 'Fore'",
                'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                    'r68:Claim[2]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
            ],
        ];

        $this->exception = new DonationDataErrorsException($donationErrors);
    }

    public function testErrorListGetter(): void
    {
        $this->assertCount(2, $this->exception->getDonationErrors());
        $this->assertEquals(
            "Invalid content found at element 'Sur'",
            $this->exception->getDonationErrors()['idA']['text'],
        );

        $this->assertEquals('Donation-specific errors', $this->exception->getMessage());
    }
}
