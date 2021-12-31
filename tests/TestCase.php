<?php

declare(strict_types=1);

namespace ClaimBot\Tests;

use ClaimBot\Messenger\Donation;
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

    protected function getTestDonation(): Donation
    {
        $testDonation = new Donation();
        $testDonation->id = 'abcd-1234';
        $testDonation->donation_date = '2021-09-10';
        $testDonation->title = 'Ms';
        $testDonation->first_name = 'Mary';
        $testDonation->last_name = 'Moore';
        $testDonation->house_no = '1a';
        $testDonation->postcode = 'N1 1AA';
        $testDonation->amount = 123.45;
        $testDonation->org_hmrc_ref = 'AB12345';
        $testDonation->org_name = 'Test Charity';

        return $testDonation;
    }
}
