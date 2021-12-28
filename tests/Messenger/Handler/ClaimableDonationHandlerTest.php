<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Messenger\Handler;

use ClaimBot\Claimer;
use ClaimBot\Messenger\Donation;
use ClaimBot\Messenger\Handler\ClaimableDonationHandler;
use ClaimBot\Messenger\OutboundMessageBus;
use ClaimBot\Tests\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\Acknowledger;

class ClaimableDonationHandlerTest extends TestCase
{
    public function testHandlerSuccess(): void
    {
        $donationA = new Donation();
        $donationA->id = 'abcd-1234';
        $donationA->donation_date = '2021-09-10';
        $donationA->title = 'Ms';
        $donationA->first_name = 'Mary';
        $donationA->last_name = 'Moore';
        $donationA->house_no = '1a';
        $donationA->postcode = 'N1 1AA';
        $donationA->amount = 123.45;
        $donationA->org_hmrc_ref = 'AB12345';

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
}
