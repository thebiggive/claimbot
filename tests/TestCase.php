<?php

declare(strict_types=1);

namespace ClaimBot\Tests;

use DI\Container;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TestCase extends PHPUnitTestCase
{
    use ProphecyTrait;

    protected function getContainer(): Container
    {
        // Instantiate PHP-DI ContainerBuilder
        $containerBuilder = new ContainerBuilder();

        // Container intentionally not compiled for tests.

        // Set up settings
        $settings = require __DIR__ . '/../app/settings.php';
        $settings($containerBuilder);

        // Set up dependencies
        $dependencies = require __DIR__ . '/../app/dependencies.php';
        $dependencies($containerBuilder);

        // Build PHP-DI Container instance
        $container = $containerBuilder->build();

        // By default, tests don't get a real logger.
        $container->set(LoggerInterface::class, new NullLogger());

        return $container;
    }
}
