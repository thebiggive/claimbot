<?php

declare(strict_types=1);

namespace ClaimBot\Tests\Settings;

use ClaimBot\Settings\Settings;
use ClaimBot\Tests\TestCase;

class SettingsTest extends TestCase
{
    public function testInitAndGet(): void
    {
        $settings = new Settings(['dummy_setting' => ['foo' => 'bar']]);

        $this->assertEquals(['foo' => 'bar'], $settings->get('dummy_setting'));
    }

    public function testSetCurrentBatchSize(): void
    {
        $settings = new Settings([]);
        $settings->setCurrentBatchSize(4);

        $this->assertEquals(4, $settings->get('current_batch_size'));
    }
}
