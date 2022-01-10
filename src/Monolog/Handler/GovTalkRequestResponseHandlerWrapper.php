<?php

declare(strict_types=1);

namespace ClaimBot\Monolog\Handler;

class GovTalkRequestResponseHandlerWrapper extends HandlerWrapper
{
    protected function shouldHandle(array $record): bool
    {
        return !empty($record['context']['direction']);
    }
}
