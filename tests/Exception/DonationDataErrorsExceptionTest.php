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
        $fullErrors = [
            'idA' => [
                'donation_id' => 'idA',
                'message' => "Invalid content found at element 'Sur'",
                'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                    'r68:Claim[1]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
            ],
            'idB' => [
                'donation_id' => 'idB',
                'message' => "Invalid content found at element 'Fore'",
                'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                    'r68:Claim[2]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
            ],
        ];

        $errorMessages = print_r([
            "Invalid content found at element 'Sur'",
            "Invalid content found at element 'Fore'",
        ], true);

        $this->exception = new DonationDataErrorsException($fullErrors, $errorMessages);
    }

    public function testErrorListGetter()
    {
        $this->assertCount(2, $this->exception->getDonationErrors());
        $this->assertEquals(
            "Invalid content found at element 'Sur'",
            $this->exception->getDonationErrors()['idA']['message'],
        );

        $this->assertEquals(<<<EOT
Array
(
    [0] => Invalid content found at element 'Sur'
    [1] => Invalid content found at element 'Fore'
)

EOT, $this->exception->getMessage());
    }
}
