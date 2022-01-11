<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Monolog\Processor;

use ClaimBot\Monolog\Processor\JustTransactionIdProcessor;
use ClaimBot\Tests\TestCase;
use Monolog\DateTimeImmutable;
use Monolog\Logger;

class JustTransactionIdProcessorTest extends TestCase
{
    public function testTransactionIdProcessing(): void
    {
        $recordIn = [
            'message' => 'Original message to be disregarded',
            'level' => Logger::INFO,
            'context' => [
                'direction' => 'request',
                'transactionId' => 'test123TxnId',
            ],
            'extra' => [],
            'datetime' => new DateTimeImmutable(true),
        ];

        $recordOut = (new JustTransactionIdProcessor())->__invoke($recordIn);

        $this->assertEquals(
            'php-govtalk logged a request for transaction ID test123TxnId',
            $recordOut['message'],
        );
    }
}
