<?php

declare(strict_types=1);

namespace ClaimBot\Tests;

use ClaimBot\Claimer;
use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Exception\HMRCRejectionException;
use ClaimBot\Exception\UnexpectedResponseException;
use GovTalk\GiftAid\ClaimingOrganisation;
use GovTalk\GiftAid\GiftAid;
use Prophecy\Argument;
use Psr\Log\NullLogger;

class ClaimerTest extends TestCase
{
    public function testClaimSuccess(): void
    {
        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->clearClaimingOrganisations()->shouldBeCalledOnce();
        $giftAidProphecy->addClaimingOrganisation(Argument::type(ClaimingOrganisation::class))
            ->shouldBeCalledOnce();
        $giftAidProphecy->setClaimToDate('2021-09-10')->shouldBeCalledOnce();
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

        $hmrcBizError = [
            'donation_id' => 'idA',
            'message' => "Invalid content found at element 'Sur'",
            'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                'r68:Claim[1]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
            'text' => 'Your submission failed due to business validation errors. Please see below for details.',
        ];

        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->clearClaimingOrganisations()->shouldBeCalledOnce();
        $giftAidProphecy->addClaimingOrganisation(Argument::type(ClaimingOrganisation::class))
            ->shouldBeCalledOnce();
        $giftAidProphecy->setClaimToDate('2021-09-10')->shouldBeCalledOnce();
        $giftAidProphecy->giftAidSubmit(Argument::type('array'))->shouldBeCalledOnce()->willReturn([
            'errors' => [
                'business' => [$hmrcBizError],
            ],
            'donation_ids_with_errors' => ['idA'],
        ]);

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
        $this->expectExceptionMessage(
            'Fatal: Authentication Failure. The supplied user credentials failed validation for the requested service.',
        );

        $hmrcFatalError = [
            'message' => 'Some fatal HMRC submission response error',
            'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/r68:Claim[1]',
            'text' => 'Authentication Failure. The supplied user credentials failed validation for the requested ' .
                'service.',
        ];

        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->clearClaimingOrganisations()->shouldBeCalledOnce();
        $giftAidProphecy->addClaimingOrganisation(Argument::type(ClaimingOrganisation::class))
            ->shouldBeCalledOnce();
        $giftAidProphecy->setClaimToDate('2021-09-10')->shouldBeCalledOnce();
        $giftAidProphecy->giftAidSubmit(Argument::type('array'))->shouldBeCalledOnce()->willReturn([
            'errors' => [
                'fatal' => [$hmrcFatalError],
            ],
        ]);

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

        $hmrcBizError = [
            'message' => 'Some general biz HMRC submission response error',
            'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/r68:Claim[1]',
            'text' => 'Some general biz HMRC submission response error text',
        ];

        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->clearClaimingOrganisations()->shouldBeCalledOnce();
        $giftAidProphecy->addClaimingOrganisation(Argument::type(ClaimingOrganisation::class))
            ->shouldBeCalledOnce();
        $giftAidProphecy->setClaimToDate('2021-09-10')->shouldBeCalledOnce();
        $giftAidProphecy->giftAidSubmit(Argument::type('array'))->shouldBeCalledOnce()->willReturn([
            'errors' => [
                'business' => [$hmrcBizError],
            ],
        ]);

        $container = $this->getContainer();
        $container->set(GiftAid::class, $giftAidProphecy->reveal());

        $claimer = new Claimer(
            $container->get(GiftAid::class),
            new NullLogger(),
        );

        $claimer->claim([$this->getTestDonation()]);
    }

    public function testNoCorrelationIdOrErrors(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('Response had neither correlation ID nor errors');

        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->clearClaimingOrganisations()->shouldBeCalledOnce();
        $giftAidProphecy->addClaimingOrganisation(Argument::type(ClaimingOrganisation::class))
            ->shouldBeCalledOnce();
        $giftAidProphecy->setClaimToDate('2021-09-10')->shouldBeCalledOnce();
        $giftAidProphecy->giftAidSubmit(Argument::type('array'))->shouldBeCalledOnce()->willReturn([]);

        $container = $this->getContainer();
        $container->set(GiftAid::class, $giftAidProphecy->reveal());

        $claimer = new Claimer(
            $container->get(GiftAid::class),
            new NullLogger(),
        );

        $claimer->claim([$this->getTestDonation()]);
    }
}