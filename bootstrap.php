<?php

declare(strict_types=1);

use DI\ContainerBuilder;

require __DIR__ . '/vendor/autoload.php';

$containerBuilder = new ContainerBuilder();

if (getenv('APP_ENV') !== 'local') { // Compile cache on staging & production
    $containerBuilder->enableCompilation(__DIR__ . '/var/cache');
}

// Build PHP-DI Container instance
return $containerBuilder->build();
