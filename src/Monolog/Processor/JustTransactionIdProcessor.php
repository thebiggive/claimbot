<?php

declare(strict_types=1);

namespace ClaimBot\Monolog\Processor;

use Monolog\Processor\ProcessorInterface;

class JustTransactionIdProcessor implements ProcessorInterface
{
    public function __invoke(array $record)
    {
        // Don't log the whole request / response to the main stream. CloudWatch Logs' separate
        // streams will have these via the hmrc-gift-aid library anyway.
        $record['message'] = sprintf(
            'php-govtalk logged a %s for transaction ID %s',
            $record['context']['direction'],
            $record['context']['transactionId'],
        );

        return $record;
    }
}
