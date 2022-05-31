<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Messenger\Handler;

use Brick\Postcode\PostcodeFormatter;
use ClaimBot\Claimer;
use ClaimBot\Exception\DonationDataErrorsException;
use ClaimBot\Exception\UnexpectedResponseException;
use ClaimBot\Messenger\Handler\ClaimableDonationHandler;
use ClaimBot\Messenger\OutboundMessageBus;
use ClaimBot\Settings\SettingsInterface;
use ClaimBot\Tests\TestCase;
use Messages\Donation;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\LogicException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class ClaimableDonationHandlerTest extends TestCase
{
    public function testSuccessFollowedByPollSuccessAndTwoDonationsToSameOrg(): void
    {
        $donationA = $this->getTestDonation();
        $donationB = clone $donationA;
        $donationB->id = 'efgh-5678';
        $donationB->house_no = '123 Main Very Long Named Named Named Named Named St';
        $donationB->postcode = 'IM1 1AA'; // Test crown dependency handling implicitly.

        $donations = [
            'abcd-1234' => $donationA,
            'efgh-5678' => $donationB,
        ];

        $donations['efgh-5678']->house_no = '123 Main Very Long Named Named Named Nam'; // truncated

        $claimerProphecy = $this->prophesize(Claimer::class);
        $claimerProphecy->claim($donations)->shouldBeCalledOnce()->willReturn(true);
        $claimerProphecy->getLastCorrelationId()->shouldBeCalledOnce()->willReturn('corrId');
        $claimerProphecy->getLastResponseMessage()->shouldBeCalledOnce()->willReturn('all good');

        $acknowledgerProphecy = $this->prophesize(Acknowledger::class);
        $acknowledgerProphecy->ack(true)->shouldBeCalledTimes(2);
        $acknowledger = $acknowledgerProphecy->reveal();

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('max_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(2); // Just 2 messages per run for this test, so we get messages ack'd right away in 1 claim.

        $donationAWithOutcomeFieldsSet = clone $donationA;
        $donationAWithOutcomeFieldsSet->submission_correlation_id = 'corrId';
        $donationAWithOutcomeFieldsSet->response_success = true;
        $donationAWithOutcomeFieldsSet->response_detail = 'all good';

        $donationBWithOutcomeFieldsSet = clone $donationB;
        $donationBWithOutcomeFieldsSet->submission_correlation_id = 'corrId';
        $donationBWithOutcomeFieldsSet->response_success = true;
        $donationBWithOutcomeFieldsSet->response_detail = 'all good';

        $donationAEnvelope = $this->getResultMessageEnvelope($donationAWithOutcomeFieldsSet);
        $donationBEnvelope = $this->getResultMessageEnvelope($donationBWithOutcomeFieldsSet);

        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($donationAEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($donationAEnvelope);
        $outboundBusProphecy->dispatch($donationBEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($donationBEnvelope);

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(OutboundMessageBus::class, $outboundBusProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(PostcodeFormatter::class),
            $container->get(SettingsInterface::class),
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(1, $handler->__invoke($donationA, $acknowledger));
        $this->assertEquals(0, $handler->__invoke($donationB, $acknowledger));
    }

    public function testSuccessFollowedByPollSuccessAndTwoOrgs(): void
    {
        $donationA = $this->getTestDonation();
        $donationB = clone $donationA;
        $donationB->id = 'efgh-5678';
        $donationB->house_no = '123 Main Very Long Named Named Named Named Named St';
        $donationB->org_hmrc_ref = 'Cd12346'; // Lowercase to test auto-formatting

        $donationsForClaim1 = [
            'abcd-1234' => $donationA,
        ];
        $donationsForClaim2 = [
            'efgh-5678' => $donationB,
        ];

        $donationsForClaim2['efgh-5678']->house_no = '123 Main Very Long Named Named Named Nam'; // truncated
        $donationsForClaim2['efgh-5678']->org_hmrc_ref = 'CD12346'; // 'D' uppercased

        $claimerProphecy = $this->prophesize(Claimer::class);
        $claimerProphecy->claim($donationsForClaim1)->shouldBeCalledOnce()->willReturn(true);
        $claimerProphecy->claim($donationsForClaim2)->shouldBeCalledOnce()->willReturn(true);
        $claimerProphecy->getLastCorrelationId()->shouldBeCalledTimes(2)->willReturn('corrId');
        $claimerProphecy->getLastResponseMessage()->shouldBeCalledTimes(2)->willReturn('all good');

        $acknowledgerProphecy = $this->prophesize(Acknowledger::class);
        $acknowledgerProphecy->ack(true)->shouldBeCalledTimes(2);
        $acknowledger = $acknowledgerProphecy->reveal();

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('max_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(2); // Just 2 messages per run for this test. Messages are ack'd right away over 2 claims.

        $donationAWithOutcomeFieldsSet = clone $donationA;
        $donationAWithOutcomeFieldsSet->submission_correlation_id = 'corrId';
        $donationAWithOutcomeFieldsSet->response_success = true;
        $donationAWithOutcomeFieldsSet->response_detail = 'all good';

        $donationBWithOutcomeFieldsSet = clone $donationB;
        $donationBWithOutcomeFieldsSet->submission_correlation_id = 'corrId';
        $donationBWithOutcomeFieldsSet->response_success = true;
        $donationBWithOutcomeFieldsSet->response_detail = 'all good';

        $donationAEnvelope = $this->getResultMessageEnvelope($donationAWithOutcomeFieldsSet);
        $donationBEnvelope = $this->getResultMessageEnvelope($donationBWithOutcomeFieldsSet);

        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($donationAEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($donationAEnvelope);
        $outboundBusProphecy->dispatch($donationBEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($donationBEnvelope);

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(OutboundMessageBus::class, $outboundBusProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(PostcodeFormatter::class),
            $container->get(SettingsInterface::class),
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(1, $handler->__invoke($donationA, $acknowledger));
        $this->assertEquals(0, $handler->__invoke($donationB, $acknowledger));
    }

    public function testSuccessFollowedByPollSuccessButWithAcknowledgerException(): void
    {
        $donationA = $this->getTestDonation();
        $donationB = clone $donationA;
        $donationB->id = 'efgh-5678';

        $donations = [
            'abcd-1234' => $donationA,
            'efgh-5678' => $donationB,
        ];

        $claimerProphecy = $this->prophesize(Claimer::class);
        $claimerProphecy->claim($donations)->shouldBeCalledOnce()->willReturn(true);
        $claimerProphecy->getLastCorrelationId()->shouldBeCalledOnce()->willReturn('corrId');
        $claimerProphecy->getLastResponseMessage()->shouldBeCalledOnce()->willReturn('all good');

        // We catch this and log an error, so for now the point of this test is just to
        // demonstrate that the exception doesn't block the process and isn't left unhandled.
        // We could expand the test to use a real logger and confirm the error is sent, but it's
        // pretty unlikely this would go wrong.
        $acknowledgerProphecy = $this->prophesize(Acknowledger::class);
        $acknowledgerProphecy->ack(true)->shouldBeCalledTimes(2)
            ->willThrow(new LogicException('Some ack error'));
        $acknowledger = $acknowledgerProphecy->reveal();

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('max_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(2); // Just 2 messages per run for this test, so we get messages ack'd right away in 1 claim.

        $donationAWithOutcomeFieldsSet = clone $donationA;
        $donationAWithOutcomeFieldsSet->submission_correlation_id = 'corrId';
        $donationAWithOutcomeFieldsSet->response_success = true;
        $donationAWithOutcomeFieldsSet->response_detail = 'all good';

        $donationBWithOutcomeFieldsSet = clone $donationB;
        $donationBWithOutcomeFieldsSet->submission_correlation_id = 'corrId';
        $donationBWithOutcomeFieldsSet->response_success = true;
        $donationBWithOutcomeFieldsSet->response_detail = 'all good';

        $donationAEnvelope = $this->getResultMessageEnvelope($donationAWithOutcomeFieldsSet);
        $donationBEnvelope = $this->getResultMessageEnvelope($donationBWithOutcomeFieldsSet);

        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($donationAEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($donationAEnvelope);
        $outboundBusProphecy->dispatch($donationBEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($donationBEnvelope);

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(OutboundMessageBus::class, $outboundBusProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(PostcodeFormatter::class),
            $container->get(SettingsInterface::class),
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(1, $handler->__invoke($donationA, $acknowledger));
        $this->assertEquals(0, $handler->__invoke($donationB, $acknowledger));
    }

    /**
     * ClaimBot handler itself picking up on an invalid postcode format and deciding not to send to HMRC at all.
     */
    public function testPostcodeValidationError(): void
    {
        $donationA = $this->getTestDonation();

        // Donation B has an invalid postcode for the GB foramtter.
        $donationB = clone $donationA;
        $donationB->id = 'efgh-5678';
        $donationB->postcode = 'N1AA';

        $donationsFull = [
            'abcd-1234' => $donationA,
            'efgh-5678' => $donationB,
        ];

        $arrayWithJustValidDonation = [
            'abcd-1234' => $donationA,
        ];

        $claimerProphecy = $this->prophesize(Claimer::class);
        $claimerProphecy->claim($donationsFull)->shouldNotBeCalled();
        $claimerProphecy->claim($arrayWithJustValidDonation)->shouldBeCalledOnce()->willReturn(true);
        $claimerProphecy->getLastCorrelationId()->shouldBeCalledOnce()->willReturn('corrId');
        $claimerProphecy->getLastResponseMessage()->shouldBeCalledOnce()->willReturn('all good with the remaining one');

        $acknowledgerProphecy = $this->prophesize(Acknowledger::class);
        $acknowledgerProphecy->ack(true)->shouldBeCalledOnce(); // true ack for $donationA
        $acknowledgerProphecy->ack(false)->shouldBeCalledOnce(); // false ack (but don't retry) for $donationB
        $acknowledger = $acknowledgerProphecy->reveal();

        $donationAWithOutcomeFieldsSet = clone $donationA;
        $donationAWithOutcomeFieldsSet->submission_correlation_id = 'corrId';
        $donationAWithOutcomeFieldsSet->response_success = true;
        $donationAWithOutcomeFieldsSet->response_detail = 'all good with the remaining one';

        $donationBWithOutcomeFieldsSet = clone $donationB;
        $donationBWithOutcomeFieldsSet->response_success = false;

        $donationAEnvelope = $this->getResultMessageEnvelope($donationAWithOutcomeFieldsSet);
        $donationBEnvelope = $this->getResultMessageEnvelope($donationBWithOutcomeFieldsSet);

        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($donationAEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($donationAEnvelope);
        $outboundBusProphecy->dispatch($donationBEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($donationBEnvelope);

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('max_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(2); // Just 2 messages per run for this test, so we get messages ack'd right away in 1 claim.

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(OutboundMessageBus::class, $outboundBusProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(PostcodeFormatter::class),
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
        $claimerProphecy->getLastCorrelationId()->shouldBeCalledOnce()->willReturn('corrId');
        $claimerProphecy->getDonationError('abcd-1234')->shouldBeCalledOnce()
            ->willReturn("Invalid content found at element 'Sur'");

        $acknowledgerProphecy = $this->prophesize(Acknowledger::class);
        // "Don't keep re-trying the claim – ack it to the original claim queue." But return value is false so this
        // is distinguisable in the expected call from a 'processed' ack.
        $acknowledgerProphecy->ack(false)->shouldBeCalledOnce();
        $acknowledger = $acknowledgerProphecy->reveal();

        $failMessageEnvelope = $this->getResultMessageEnvelope($donation);
        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($failMessageEnvelope)
            ->shouldBeCalledOnce()
            ->willReturn($failMessageEnvelope);

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('max_batch_size')
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
            $container->get(PostcodeFormatter::class),
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

        $claimerProphecy->getLastCorrelationId()
            ->shouldBeCalledTimes(2) // Once for failure, once for success acks.
            ->willReturn('corrId');

        $claimerProphecy->getDonationError('abcd-1234')
            ->shouldBeCalledOnce()
            ->willReturn("Invalid content found at element 'Sur'");

        $claimerProphecy->getLastResponseMessage()
            ->shouldBeCalledOnce()
            ->willReturn('all good with the remaining one');

        $acknowledger1Prophecy = $this->prophesize(Acknowledger::class);
        // "Don't keep re-trying the claim – ack it to the original claim queue." But return value is false so this
        // is distinguisable in the expected call from a 'processed' ack.
        $acknowledger1Prophecy->ack(false)->shouldBeCalledOnce();
        $acknowledger1 = $acknowledger1Prophecy->reveal();

        $acknowledger2Prophecy = $this->prophesize(Acknowledger::class);
        $acknowledger2Prophecy->ack(true)
            ->shouldBeCalledOnce();
        $acknowledger2 = $acknowledger2Prophecy->reveal();

        $donation1WithOutcomeFieldsSet = clone $donation1;
        $donation1WithOutcomeFieldsSet->submission_correlation_id = 'corrId';
        $donation1WithOutcomeFieldsSet->response_success = false;
        $donation1WithOutcomeFieldsSet->response_detail = "Invalid content found at element 'Sur'";

        $donation2WithOutcomeFieldsSet = clone $donation2;
        $donation2WithOutcomeFieldsSet->submission_correlation_id = 'corrId';
        $donation2WithOutcomeFieldsSet->response_success = true;
        $donation2WithOutcomeFieldsSet->response_detail = 'all good with the remaining one';

        $donation1Envelope = $this->getResultMessageEnvelope($donation1WithOutcomeFieldsSet);
        $donation2Envelope = $this->getResultMessageEnvelope($donation2WithOutcomeFieldsSet);

        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($donation1Envelope)
            ->shouldBeCalledOnce()
            ->willReturn($donation1Envelope);
        $outboundBusProphecy->dispatch($donation2Envelope)
            ->shouldBeCalledOnce()
            ->willReturn($donation2Envelope);

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('max_batch_size')
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
            $container->get(PostcodeFormatter::class),
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

        $claimerProphecy->getLastCorrelationId()
            ->shouldBeCalledOnce()
            ->willReturn('corrId');

        $claimerProphecy->getDonationError('abcd-1234')
            ->shouldBeCalledOnce()
            ->willReturn("Invalid content found at element 'Sur'");

        $acknowledger1Prophecy = $this->prophesize(Acknowledger::class);
        // "Don't keep re-trying the claim – ack it to the original claim queue." But return value is false so this
        // is distinguisable in the expected call from a 'processed' ack.
        $acknowledger1Prophecy->ack(false)->shouldBeCalledOnce();
        $acknowledger1 = $acknowledger1Prophecy->reveal();

        $acknowledger2Prophecy = $this->prophesize(Acknowledger::class);
        $acknowledger2Prophecy->nack(Argument::type(UnexpectedResponseException::class))
            ->shouldBeCalledOnce();
        $acknowledger2 = $acknowledger2Prophecy->reveal();

        $failMessageEnvelope1 = $this->getResultMessageEnvelope($donation1);
        $failMessageEnvelope2 = $this->getResultMessageEnvelope($donation2);
        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($failMessageEnvelope1)
            ->shouldBeCalledOnce()
            ->willReturn($failMessageEnvelope1);
        $outboundBusProphecy->dispatch($failMessageEnvelope2)
            ->shouldBeCalledOnce()
            ->willReturn($failMessageEnvelope2);

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('max_batch_size')
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
            $container->get(PostcodeFormatter::class),
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
        $claimerProphecy->getLastCorrelationId()->shouldBeCalledOnce()->willReturn('corrId');
        $claimerProphecy->getDonationError('abcd-1234')->shouldBeCalledOnce()
            ->willReturn("Invalid content found at element 'Sur'");

        $acknowledgerProphecy = $this->prophesize(Acknowledger::class);
        // "Don't keep re-trying the claim – ack it to the original claim queue." But return value is false so this
        // is distinguisable in the expected call from a 'processed' ack. As below, we also still expect to ack()
        // messages with data errors even if the dispatch to the failure queue hits an unexpected error – we log this
        // with ERROR severity so that we would know to follow up.
        $acknowledgerProphecy->ack(false)->shouldBeCalledOnce();
        $acknowledger = $acknowledgerProphecy->reveal();

        $failMessageEnvelope = $this->getResultMessageEnvelope($donation);
        $transportException = new TransportException('Result queue fell over');
        $outboundBusProphecy = $this->prophesize(OutboundMessageBus::class);
        // https://github.com/phpspec/prophecy/issues/463#issuecomment-574123290
        $outboundBusProphecy->dispatch($failMessageEnvelope)
            ->shouldBeCalledOnce()
            // When this happens we log an error but still ack() the donation to the original queue.
            ->willThrow($transportException);

        $settingsProphecy = $this->prophesize(SettingsInterface::class);
        $settingsProphecy->get('max_batch_size')
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
            $container->get(PostcodeFormatter::class),
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
        $settingsProphecy->get('max_batch_size')
            ->shouldBeCalledOnce()
            ->willReturn(1); // Send claim after just 1 message.

        $container = $this->getContainer();
        $container->set(Claimer::class, $claimerProphecy->reveal());
        $container->set(SettingsInterface::class, $settingsProphecy->reveal());

        $handler = new ClaimableDonationHandler(
            $container->get(Claimer::class),
            $container->get(LoggerInterface::class),
            $container->get(OutboundMessageBus::class),
            $container->get(PostcodeFormatter::class),
            $container->get(SettingsInterface::class),
        );

        // These return "The number of pending messages in the batch if $ack is not null".
        $this->assertEquals(0, $handler->__invoke($donation, $acknowledger));
    }

    private function getResultMessageEnvelope(Donation $donation): Envelope
    {
        $failMessageStamps = [
            new BusNameStamp('claimbot.donation.result'),
            new TransportMessageIdStamp("claimbot.donation.result.{$donation->id}"),
        ];

        return new Envelope($donation, $failMessageStamps);
    }
}
