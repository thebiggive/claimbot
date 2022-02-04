<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Messenger\Handler;

use ClaimBot\Claimer;
use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Exception\UnexpectedResponseException;
use ClaimBot\Messenger\Handler\ClaimableDonationHandler;
use ClaimBot\Messenger\OutboundMessageBus;
use ClaimBot\Settings\SettingsInterface;
use ClaimBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class ClaimableDonationHandlerTest extends TestCase
{
    public function testHandlerSuccess(): void
    {
        $donationA = $this->getTestDonation();
        $donationB = clone $donationA;
        $donationB->id = 'efgh-5678';
        $donationB->org_hmrc_ref = 'CD12346';

        $donations = [
            'abcd-1234' => $donationA,
            'efgh-5678' => $donationB,
        ];

        $claimerProphecy = $this->prophesize(Claimer::class);
        $claimerProphecy->claim($donations)->shouldBeCalledOnce()->willReturn(true);

        $acknowledgerProphecy = $this->prophesize(Acknowledger::class);
        $acknowledgerProphecy->ack(true)->shouldBeCalledTimes(2);
        $acknowledger = $acknowledgerProphecy->reveal();

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('current_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(2); // Just 2 messages per run for this test, so we get messages ack'd right away in 1 claim.

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(SettingsInterface::class),
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(1, $handler->__invoke($donationA, $acknowledger));
        $this->assertEquals(0, $handler->__invoke($donationB, $acknowledger));
    }

    public function testDonationDataError(): void
    {
        $dataException = new DonationDataErrorsException(
            [
                'abcd-1234' => [
                    'donation_id' => 'abcd-1234',
                    'message' => "Invalid content found at element 'Sur'",
                    'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                        'r68:Claim[1]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
                ],
            ],
            // Specific error string not used here and is tested specifically in DonationDataErrorsExceptionTest.
            'Array [...]',
        );

        $donation = $this->getTestDonation();
        $claimerProphecy = $this->prophesize(Claimer::class);
        $claimerProphecy->claim(['abcd-1234' => $donation])
            ->shouldBeCalledOnce()
            ->willThrow($dataException);
        $claimerProphecy->getRemainingValidDonations()
            ->shouldBeCalledOnce()
            ->willReturn([]);

        $acknowledgerProphecy = $this->prophesize(Acknowledger::class);
        // "Don't keep re-trying the claim – ack it to the original claim queue." But return value is false so this
        // is distinguisable in the expected call from a 'processed' ack.
        $acknowledgerProphecy->ack(false)->shouldBeCalledOnce();
        $acknowledger = $acknowledgerProphecy->reveal();

        $failMessageStamps = [
            new BusNameStamp('claimbot.donation.error'),
            new TransportMessageIdStamp('claimbot.donation.error.abcd-1234'),
        ];
        $failMessageEnvelope = new Envelope($donation, $failMessageStamps);

        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($failMessageEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($failMessageEnvelope);

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('current_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(1); // Send claim after just 1 message.

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(OutboundMessageBus::class, $outboundBusProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(SettingsInterface::class),
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(0, $handler->__invoke($donation, $acknowledger));
    }

    public function testDonationDataWithRetrySuccess(): void
    {
        $dataException = new DonationDataErrorsException(
            [
                'abcd-1234' => [
                    'donation_id' => 'abcd-1234',
                    'message' => "Invalid content found at element 'Sur'",
                    'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                        'r68:Claim[1]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
                ],
            ],
            // Specific error string not used here and is tested specifically in DonationDataErrorsExceptionTest.
            'Array [...]',
        );

        $donation1 = $this->getTestDonation();
        $donation2 = clone $donation1;
        $donation2->id = 'efgh-5678';
        $claimerProphecy = $this->prophesize(Claimer::class);

        // First claim for 2x donations gives a single donation error.
        $claimerProphecy->claim(['abcd-1234' => $donation1, 'efgh-5678' => $donation2])
            ->shouldBeCalledOnce()
            ->willThrow($dataException);

        $claimerProphecy->getRemainingValidDonations()
            ->shouldBeCalledOnce()
            ->willReturn(['efgh-5678' => $donation2]);

        // Second claim for the remaining donation gives a general error.
        $claimerProphecy->claim(['efgh-5678' => $donation2])
            ->shouldBeCalledOnce()
            ->willReturn(true);

        $acknowledger1Prophecy = $this->prophesize(Acknowledger::class);
        // "Don't keep re-trying the claim – ack it to the original claim queue." But return value is false so this
        // is distinguisable in the expected call from a 'processed' ack.
        $acknowledger1Prophecy->ack(false)->shouldBeCalledOnce();
        $acknowledger1 = $acknowledger1Prophecy->reveal();

        $acknowledger2Prophecy = $this->prophesize(Acknowledger::class);
        $acknowledger2Prophecy->ack(true)
            ->shouldBeCalledOnce();
        $acknowledger2 = $acknowledger2Prophecy->reveal();

        $failMessageStamps1 = [
            new BusNameStamp('claimbot.donation.error'),
            new TransportMessageIdStamp('claimbot.donation.error.abcd-1234'),
        ];
        $failMessageEnvelope = new Envelope($donation1, $failMessageStamps1);

        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($failMessageEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($failMessageEnvelope);

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('current_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(2);

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(OutboundMessageBus::class, $outboundBusProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(SettingsInterface::class),
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(1, $handler->__invoke($donation1, $acknowledger1));
        $this->assertEquals(0, $handler->__invoke($donation2, $acknowledger2));
    }

    public function testDonationDataWithRetryFollowedByGeneralError(): void
    {
        $dataException = new DonationDataErrorsException(
            [
                'abcd-1234' => [
                    'donation_id' => 'abcd-1234',
                    'message' => "Invalid content found at element 'Sur'",
                    'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                        'r68:Claim[1]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
                ],
            ],
            // Specific error string not used here and is tested specifically in DonationDataErrorsExceptionTest.
            'Array [...]',
        );

        $donation1 = $this->getTestDonation();
        $donation2 = clone $donation1;
        $donation2->id = 'efgh-5678';
        $claimerProphecy = $this->prophesize(Claimer::class);

        // First claim for 2x donations gives a single donation error.
        $claimerProphecy->claim(['abcd-1234' => $donation1, 'efgh-5678' => $donation2])
            ->shouldBeCalledOnce()
            ->willThrow($dataException);

        $claimerProphecy->getRemainingValidDonations()
            ->shouldBeCalledOnce()
            ->willReturn(['efgh-5678' => $donation2]);

        // Second claim for the remaining donation gives a general error.
        $claimerProphecy->claim(['efgh-5678' => $donation2])
            ->shouldBeCalledOnce()
            ->willThrow(UnexpectedResponseException::class);

        $acknowledger1Prophecy = $this->prophesize(Acknowledger::class);
        // "Don't keep re-trying the claim – ack it to the original claim queue." But return value is false so this
        // is distinguisable in the expected call from a 'processed' ack.
        $acknowledger1Prophecy->ack(false)->shouldBeCalledOnce();
        $acknowledger1 = $acknowledger1Prophecy->reveal();

        $acknowledger2Prophecy = $this->prophesize(Acknowledger::class);
        $acknowledger2Prophecy->nack(Argument::type(UnexpectedResponseException::class))
            ->shouldBeCalledOnce();
        $acknowledger2 = $acknowledger2Prophecy->reveal();

        $failMessageStamps1 = [
            new BusNameStamp('claimbot.donation.error'),
            new TransportMessageIdStamp('claimbot.donation.error.abcd-1234'),
        ];
        $failMessageEnvelope = new Envelope($donation1, $failMessageStamps1);

        $failMessageStamps2 = [
            new BusNameStamp('claimbot.donation.error'),
            new TransportMessageIdStamp('claimbot.donation.error.efgh-5678'),
        ];
        $failMessageEnvelope2 = new Envelope($donation2, $failMessageStamps2);

        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($failMessageEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($failMessageEnvelope);
        $outboundBusProphecy->dispatch($failMessageEnvelope2)
            ->shouldBeCalledOnce()
            ->willReturn($failMessageEnvelope2);

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('current_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(2);

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(OutboundMessageBus::class, $outboundBusProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(SettingsInterface::class),
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(1, $handler->__invoke($donation1, $acknowledger1));
        $this->assertEquals(0, $handler->__invoke($donation2, $acknowledger2));
    }

    public function testDonationDataErrorAndFailureQueueDispatchError(): void
    {
        $dataException = new DonationDataErrorsException(
            [
                'abcd-1234' => [
                    'donation_id' => 'abcd-1234',
                    'message' => "Invalid content found at element 'Sur'",
                    'location' => '/hd:GovTalkMessage[1]/hd:Body[1]/r68:IRenvelope[1]/r68:R68[1]/' .
                        'r68:Claim[1]/r68:Repayment[1]/r68:GAD[1]/r68:Donor[1]/r68:Sur[1]',
                ],
            ],
            // Specific error string not used here and is tested specifically in DonationDataErrorsExceptionTest.
            'Array [...]',
        );

        $donation = $this->getTestDonation();
        $claimerProphecy = $this->prophesize(Claimer::class);
        $claimerProphecy->claim(['abcd-1234' => $donation])->willThrow($dataException);
        $claimerProphecy->getRemainingValidDonations()
            ->shouldBeCalledOnce()
            ->willReturn([]);

        $acknowledgerProphecy = $this->prophesize(Acknowledger::class);
        // "Don't keep re-trying the claim – ack it to the original claim queue." But return value is false so this
        // is distinguisable in the expected call from a 'processed' ack. As below, we also still expect to ack()
        // messages with data errors even if the dispatch to the failure queue hits an unexpected error – we log this
        // with ERROR severity so that we would know to follow up.
        $acknowledgerProphecy->ack(false)->shouldBeCalledOnce();
        $acknowledger = $acknowledgerProphecy->reveal();

        $failMessageStamps = [
            new BusNameStamp('claimbot.donation.error'),
            new TransportMessageIdStamp('claimbot.donation.error.abcd-1234'),
        ];
        $failMessageEnvelope = new Envelope($donation, $failMessageStamps);

        $transportException = new TransportException('Failure queue fell over');

        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($failMessageEnvelope)
            ->shouldBeCalledOnce()
            // When this happens we log an error but still ack() the donation to the original queue.
            ->willThrow($transportException);

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('current_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(1); // Send claim after just 1 message.

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(OutboundMessageBus::class, $outboundBusProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(SettingsInterface::class),
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(0, $handler->__invoke($donation, $acknowledger));
    }

    public function testHMRCError(): void
    {
        $donation = $this->getTestDonation();
        $claimerProphecy = $this->prophesize(Claimer::class);
        $claimerProphecy->claim(['abcd-1234' => $donation])->willThrow(UnexpectedResponseException::class);

        $acknowledgerProphecy = $this->prophesize(Acknowledger::class);
        $acknowledgerProphecy->nack(Argument::type(UnexpectedResponseException::class))->shouldBeCalledOnce();
        $acknowledger = $acknowledgerProphecy->reveal();

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('current_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(1); // Send claim after just 1 message.

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(SettingsInterface::class),
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(0, $handler->__invoke($donation, $acknowledger));
    }
}
