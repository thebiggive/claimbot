<?php

declare(strict_types=1);

namespace ClaimBot\Settings;

class Settings implements SettingsInterface
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function get(string $key = '')
    {
        return (empty($key)) ? $this->settings : $this->settings[$key];
    }

    public function setCurrentBatchSize(int $batchSize): void
    {
        $this->settings['current_batch_size'] = $batchSize;
    }
}
