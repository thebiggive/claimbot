<?php

declare(strict_types=1);

namespace ClaimBot\Monolog\Handler;

use Monolog\Handler\HandlerWrapper as MonologHandlerWrapper;

/**
 * Note that `isHandling(...)` receives only the 'level' on its `$record`, so we don't bother defining
 * it since we filter on tags.
 */
abstract class HandlerWrapper extends MonologHandlerWrapper
{
    abstract protected function shouldHandle(array $record): bool;

    public function handle(array $record): bool
    {
        if (!$this->shouldHandle($record)) {
            return false;
        }

        return $this->handler->handle($record);
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }
}
