<?php

declare(strict_types=1);

use DI\ContainerBuilder;

require __DIR__ . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();

if (getenv('APP_ENV') !== 'local') { // Compile cache on staging & production
    $containerBuilder->enableCompilation(__DIR__ . '/var/cache');
}

$settings = require __DIR__ . '/app/settings.php';
$settings($containerBuilder);

$dependencies = require __DIR__ . '/app/dependencies.php';
$dependencies($containerBuilder);

// Build PHP-DI Container instance
return $containerBuilder->build();
