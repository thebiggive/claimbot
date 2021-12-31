<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    // Global Settings Object
    $containerBuilder->addDefinitions([
        'settings' => [
            'environment' => getenv('APP_ENV'),
            'logger' => [
                'name' => 'claimbot',
                'path' => 'php://stdout',
                'level' => Logger::DEBUG,
                'cloudwatch' => [
                    'key' => getenv('AWS_LOGS_ACCESS_KEY_ID'),
                    'secret' => getenv('AWS_LOGS_SECRET_ACCESS_KEY'),
                    'region' => getenv('AWS_LOGS_REGION'),
                ],
            ],
            'version' => getenv('APP_VERSION'),
        ],
    ]);
};
