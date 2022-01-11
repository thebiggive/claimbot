<?php

declare(strict_types=1);

namespace ClaimBot\Settings;

interface SettingsInterface
{
    public function get(string $key = '');
}
