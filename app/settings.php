<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    // Global Settings Object
    $containerBuilder->addDefinitions([
        'settings' => [
            'logger' => [
                'name' => 'claimbot',
                'path' => 'php://stdout',
                'level' => Logger::DEBUG,
            ],
            'version' => getenv('APP_VERSION'),
        ],
    ]);
};
