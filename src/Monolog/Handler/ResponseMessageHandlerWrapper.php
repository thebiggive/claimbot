<?php

declare(strict_types=1);

namespace ClaimBot\Monolog\Handler;

class ResponseMessageHandlerWrapper extends HandlerWrapper
{
    protected function shouldHandle(array $record): bool
    {
        return ($record['context']['gift_aid_message'] ?? null) === 'response';
    }
}
