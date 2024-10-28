<?php

declare(strict_types=1);

namespace MatchBot\Application\Messenger\Stamp;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Temporarily copied from matchbot to fix MessageDecodingFailedException for messages with this stamp. Not actually
 * referenced anywhere in claimbot.
 */
readonly class MessageId implements StampInterface
{
    private UuidInterface $messageId;

    public function __construct()
    {
        $this->messageId = Uuid::uuid4();
    }

    public function getMessageId(): string
    {
        return $this->messageId->toString();
    }
}
