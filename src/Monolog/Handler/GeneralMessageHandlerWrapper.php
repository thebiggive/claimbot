<?php

declare(strict_types=1);

namespace ClaimBot\Monolog\Handler;

class GeneralMessageHandlerWrapper extends HandlerWrapper
{
    protected function shouldHandle(array $record): bool
    {
        return (
            empty($record['context']['direction']) &&
            empty($record['context']['gift_aid_message'])
        );
    }
}
