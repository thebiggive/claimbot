<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Monolog\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use ClaimBot\Monolog\Handler\ClaimBotHandler;
use ClaimBot\Tests\TestCase;
use Monolog\DateTimeImmutable;
use Monolog\Logger;

class ClaimBotHandlerTest extends TestCase
{
    public function testHandler(): void
    {
        $container = $this->getContainer();
        $container->set(
            CloudWatchLogsClient::class,
            $this->prophesize(CloudWatchLogsClient::class)->reveal(),
        );

        $handler = new ClaimBotHandler(
            $container->get(CloudWatchLogsClient::class),
            [
                'name' => 'claimbot',
                'path' => '/dev/null', // We don't want to print to stdout in tests.
                'level' => Logger::DEBUG,
                'cloudwatch' => [
                    'key' => 'unit-test-aws-key',
                    'secret' => 'unit-test-aws-secret',
                    'region' => 'eu-west-1',
                ],
            ],
            'test',
        );

        /**
         * We set 'bubble' to true, because we need all handlers in the group to see each message, since the
         * 'routing' of messages is based on context and not level. This means `GroupHandler` will always return
         * false on {@see \Monolog\Handler\GroupHandler::handle()}, regardless of whether a handler did something
         * with the log, so that logs are still provided to the rest of the handler group.
         */
        $this->assertFalse(
            $handler->handle([
                'message' => 'Some standard log error message',
                'level' => Logger::ERROR,
                'context' => [],
                'extra' => [],
                'datetime' => new DateTimeImmutable(true),
            ]),
        );

        $this->assertFalse(
            $handler->handle([
                'message' => 'Some Gift Aid request data',
                'level' => Logger::INFO,
                'context' => ['gift_aid_message' => 'request'],
                'extra' => [],
                'datetime' => new DateTimeImmutable(true),
            ]),
        );

        $this->assertFalse(
            $handler->handle([
                'message' => 'Some Gift Aid response data',
                'level' => Logger::INFO,
                'context' => ['gift_aid_message' => 'response'],
                'extra' => [],
                'datetime' => new DateTimeImmutable(true),
            ]),
        );
    }
}
