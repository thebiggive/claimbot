<?php

declare(strict_types=1);

namespace ClaimBot\Tests;

use ClaimBot\Claimer;
use GovTalk\GiftAid\GiftAid;
use Prophecy\Argument;
use Psr\Log\NullLogger;

/**
 * @todo test failure modes
 */
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
}
