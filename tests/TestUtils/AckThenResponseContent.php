<?php

declare(strict_types=1);

namespace ClaimBot\Tests\TestUtils;

use Prophecy\Promise\PromiseInterface;
use Prophecy\Prophecy\MethodProphecy;
use Prophecy\Prophecy\ObjectProphecy;

class AckThenResponseContent implements PromiseInterface
{
    private int $callsCount = 0;

    public function execute(array $args, ObjectProphecy $object, MethodProphecy $method)
    {
        if ($this->callsCount === 0) {
            $this->callsCount++;

            return [
                'endpoint' => 'https://example.local/poll',
                'interval' => '1',
                'correlationid' => 'someCorrId123',
                'submission_request' => '<?xml not-real-request-xml ?>',
            ];
        }

        return [
            'submission_response' => ['message' => ['Thanks for your submission...']],
        ];
    }
}
