<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Messenger\Handler;

use ClaimBot\Claimer;
use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Exception\UnexpectedResponseException;
use ClaimBot\Messenger\Donation;
use ClaimBot\Messenger\Handler\ClaimableDonationHandler;
use ClaimBot\Messenger\OutboundMessageBus;
use ClaimBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
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

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            2, // Just 2 messages per run for this test, so we get messages ack'd right away in 1 claim.
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
        $claimerProphecy->claim(['abcd-1234' => $donation])->willThrow($dataException);

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

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(OutboundMessageBus::class, $outboundBusProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            1, // Send claim after just 1 message.
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

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            1, // Send claim after just 1 message.
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(0, $handler->__invoke($donation, $acknowledger));
    }

    private function getTestDonation(): Donation
    {
        $testDonation = new Donation();
        $testDonation->id = 'abcd-1234';
        $testDonation->donation_date = '2021-09-10';
        $testDonation->title = 'Ms';
        $testDonation->first_name = 'Mary';
        $testDonation->last_name = 'Moore';
        $testDonation->house_no = '1a';
        $testDonation->postcode = 'N1 1AA';
        $testDonation->amount = 123.45;
        $testDonation->org_hmrc_ref = 'AB12345';

        return $testDonation;
    }
}
