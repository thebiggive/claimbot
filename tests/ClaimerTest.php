<?php

declare(strict_types=1);

namespace ClaimBot\Tests;

use ClaimBot\Claimer;
use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Exception\HMRCRejectionException;
use GovTalk\GiftAid\GiftAid;
use Prophecy\Argument;
use Psr\Log\NullLogger;

class ClaimerTest extends TestCase
{
    public function testClaimSuccess(): void
    {
        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->giftAidSubmit(Argument::type('array'))->willReturn([
            'correlationid' => 'someCorrId123',
            'claim_data_xml' => '<?xml not-real-response-xml ?>',
            'submission_request' => '<?xml not-real-request-xml ?>',
        ]);

        $container = $this->getContainer();
        $container->set(GiftAid::class, $giftAidProphecy->reveal());

        $claimer = new Claimer(
            $container->get(GiftAid::class),
            new NullLogger(),
        );

        $claimResult = $claimer->claim([$this->getTestDonation()]);
        $this->assertTrue($claimResult);
    }

    public function testDonationSpecificError(): void
    {
        $this->expectException(DonationDataErrorsException::class);

        $donationErrorsException = new DonationDataErrorsException([
            'idA' => [
                'donation_id' => 'idA',
                'message' => "Invalid content found at element 'Sur'",
                'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                    'r68:Claim[1]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
            ],
        ]);
        $donationErrorsException->setRawHMRCErrors([
            'business' => [
                [
                    'donation_id' => 'idA',
                    'message' => "Invalid content found at element 'Sur'",
                    'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                        'r68:Claim[1]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
                ],
            ],
        ]);

        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->giftAidSubmit(Argument::type('array'))->willThrow($donationErrorsException);

        $container = $this->getContainer();
        $container->set(GiftAid::class, $giftAidProphecy->reveal());

        $claimer = new Claimer(
            $container->get(GiftAid::class),
            new NullLogger(),
        );

        $claimer->claim([$this->getTestDonation()]);
    }

    public function testGeneralFatalError(): void
    {
        $this->expectException(HMRCRejectionException::class);
        $this->expectExceptionMessage('Fatal: Some fatal HMRC submission response error');

        $generalFatalErrorException = new HMRCRejectionException('Fatal: Some fatal HMRC submission response error');
        $generalFatalErrorException->setRawHMRCErrors([
            'fatal' => [
                [
                    'message' => 'Some fatal HMRC submission response error',
                    'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/r68:Claim[1]',
                ],
            ],
        ]);

        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->giftAidSubmit(Argument::type('array'))->willThrow($generalFatalErrorException);

        $container = $this->getContainer();
        $container->set(GiftAid::class, $giftAidProphecy->reveal());

        $claimer = new Claimer(
            $container->get(GiftAid::class),
            new NullLogger(),
        );

        $claimer->claim([$this->getTestDonation()]);
    }

    public function testGeneralOtherErrors(): void
    {
        $this->expectException(HMRCRejectionException::class);
        $this->expectExceptionMessage('HMRC submission errors');

        $generalFatalErrorException = new HMRCRejectionException('HMRC submission errors');
        $generalFatalErrorException->setRawHMRCErrors([
            'fatal' => [],
            'business' => [
                [
                    'message' => 'Some general biz HMRC submission response error',
                    'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/r68:Claim[1]',
                ],
            ],
        ]);

        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->giftAidSubmit(Argument::type('array'))->willThrow($generalFatalErrorException);

        $container = $this->getContainer();
        $container->set(GiftAid::class, $giftAidProphecy->reveal());

        $claimer = new Claimer(
            $container->get(GiftAid::class),
            new NullLogger(),
        );

        $claimer->claim([$this->getTestDonation()]);
    }
}
