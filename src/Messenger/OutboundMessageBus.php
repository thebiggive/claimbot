<?php

declare(strict_types=1);

namespace ClaimBot\Messenger;

use Symfony\Component\Messenger\MessageBus;

/**
 * A bus for delivering errors back out to SQS. This needs to be distinct from the `MessageBusInterface::class`
 * definition in DI so that we can make it available to {@see \ClaimBot\Messenger\Handler\ClaimableDonationHandler}
 * without setting up a circular dependency chain.
 */
class OutboundMessageBus extends MessageBus
{
    public function __construct(iterable $middlewareHandlers = [])
    {
        parent::__construct($middlewareHandlers);
    }
}
