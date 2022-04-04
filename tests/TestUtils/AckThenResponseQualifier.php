<?php

declare(strict_types=1);

namespace ClaimBot\Tests\TestUtils;

use Prophecy\Promise\PromiseInterface;
use Prophecy\Prophecy\MethodProphecy;
use Prophecy\Prophecy\ObjectProphecy;

class AckThenResponseQualifier implements PromiseInterface
{
    private int $callsCount = 0;

    public function execute(array $args, ObjectProphecy $object, MethodProphecy $method)
    {
        if ($this->callsCount === 0) {
            $this->callsCount++;

            return 'acknowledgement';
        }

        return 'response';
    }
}
