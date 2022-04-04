<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Commands;

use ClaimBot\Claimer;
use ClaimBot\Commands\Poll;
use ClaimBot\Exception\HMRCRejectionException;
use ClaimBot\Settings\SettingsInterface;
use ClaimBot\Tests\TestCase;
use GovTalk\GiftAid\GiftAid;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

class PollTest extends TestCase
{
    public function testMissingCorrelationIdDoesNotRun(): void
    {
        $command = $this->getCommandWithClaimer($this->getContainer()->get(Claimer::class));
        $commandTester = new CommandTester($command);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "correlationId").');

        $commandTester->execute([]);
    }

    public function testClaimExceptionDuringPoll(): void
    {
        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->getEndpoint(true)->shouldBeCalledOnce()
            ->willReturn('http://host.docker.internal:5665/submission');

        $giftAidProphecy->declarationResponsePoll('ABC123', 'http://host.docker.internal:5665/poll')
            ->shouldBeCalledOnce()
            ->willThrow(new HMRCRejectionException('HMRC rejection reason'));

        $claimer = new Claimer($giftAidProphecy->reveal(), new NullLogger());

        $command = $this->getCommandWithClaimer($claimer);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['correlationId' => 'ABC123']);

        $expectedOutputLines = [
            'claimbot:poll starting!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(20, $commandTester->getStatusCode());
    }

    public function testMiscExceptionDuringPoll(): void
    {
        $giftAidProphecy = $this->prophesize(GiftAid::class);
        $giftAidProphecy->getEndpoint(true)->shouldBeCalledOnce()
            ->willReturn('http://host.docker.internal:5665/submission');

        $giftAidProphecy->declarationResponsePoll('ABC123', 'http://host.docker.internal:5665/poll')
            ->shouldBeCalledOnce()
            ->willThrow(new \LogicException('Some internal error'));

        $claimer = new Claimer($giftAidProphecy->reveal(), new NullLogger());

        $command = $this->getCommandWithClaimer($claimer);
        $commandTester = new CommandTester($command);
        $commandTester->execute(['correlationId' => 'ABC123']);

        $expectedOutputLines = [
            'claimbot:poll starting!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(30, $commandTester->getStatusCode());
    }

    public function testPollTimeout(): void
    {
        $claimerProphecy = $this->prophesize(Claimer::class);
        $claimerProphecy->getDefaultPollUrl(true)
            ->shouldBeCalledOnce()
            ->willReturn('http://host.docker.internal:5665/poll');
        $claimerProphecy->pollForResponse('ABC123', 'http://host.docker.internal:5665/poll')
            ->shouldBeCalledOnce()
            ->willReturn(false);
        $claimerProphecy->getLastResponseMessage()->shouldNotBeCalled();

        $command = $this->getCommandWithClaimer($claimerProphecy->reveal());
        $commandTester = new CommandTester($command);
        $commandTester->execute(['correlationId' => 'ABC123']);

        $expectedOutputLines = [
            'claimbot:poll starting!',
            'claimbot:poll complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(10, $commandTester->getStatusCode());
    }

    public function testPollSuccess(): void
    {
        $claimerProphecy = $this->prophesize(Claimer::class);
        $claimerProphecy->getDefaultPollUrl(true)
            ->shouldBeCalledOnce()
            ->willReturn('http://host.docker.internal:5665/poll');
        $claimerProphecy->pollForResponse('ABC123', 'http://host.docker.internal:5665/poll')
            ->shouldBeCalledOnce()
            ->willReturn(true);
        $claimerProphecy->getLastResponseMessage()
            ->shouldBeCalledOnce()
            ->willReturn('<?xml a-response ?>');

        $command = $this->getCommandWithClaimer($claimerProphecy->reveal());
        $commandTester = new CommandTester($command);
        $commandTester->execute(['correlationId' => 'ABC123']);

        $expectedOutputLines = [
            'claimbot:poll starting!',
            'claimbot:poll – Poll success.',
            'claimbot:poll – Full HMRC XML response for correlation ID ABC123: <?xml a-response ?>',
            'claimbot:poll complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    protected function getCommandWithClaimer(Claimer $claimer): Poll
    {
        return new Poll(
            $claimer,
            new NullLogger(),
            $this->getContainer()->get(SettingsInterface::class),
        );
    }
}
