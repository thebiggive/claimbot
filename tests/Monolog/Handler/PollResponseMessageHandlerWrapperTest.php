<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Monolog\Handler;

use ClaimBot\Monolog\Handler\PollResponseMessageHandlerWrapper;
use ClaimBot\Tests\TestCase;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

class PollResponseMessageHandlerWrapperTest extends TestCase
{
    private PollResponseMessageHandlerWrapper $handler;

    public function setUp(): void
    {
        $this->handler = new PollResponseMessageHandlerWrapper(new NullHandler());
    }

    public function testNoContextMessageIsNotHandled(): void
    {
        $log = [
            'message' => 'Some standard log error message',
            'level' => Logger::ERROR,
            'context' => [],
            'extra' => [],
        ];

        $this->assertFalse($this->handler->handle($log));
    }

    public function testOtherContextMessageIsNotHandled(): void
    {
        $log = [
            'message' => 'Some Gift Aid response data',
            'level' => Logger::INFO,
            'context' => ['gift_aid_message' => 'response'],
            'extra' => [],
        ];

        $this->assertFalse($this->handler->handle($log));
    }

    public function testMatchingContextMessageIsHandled(): void
    {
        $log = [
            'message' => 'Some Gift Aid request data',
            'level' => Logger::INFO,
            'context' => ['gift_aid_message' => 'poll_response'],
            'extra' => [],
        ];

        $this->assertTrue($this->handler->handle($log));
    }
}
