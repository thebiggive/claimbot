<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Monolog\Handler;

use ClaimBot\Monolog\Handler\ClaimRequestMessageHandlerWrapper;
use ClaimBot\Tests\TestCase;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

class ClaimRequestMessageHandlerWrapperTest extends TestCase
{
    private ClaimRequestMessageHandlerWrapper $handler;

    public function setUp(): void
    {
        $this->handler = new ClaimRequestMessageHandlerWrapper(new NullHandler());
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
            'context' => ['gift_aid_message' => 'request'],
            'extra' => [],
        ];

        $this->assertTrue($this->handler->handle($log));
    }
}
