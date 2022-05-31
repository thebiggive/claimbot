<?php

declare(strict_types=1);

use ClaimBot\Settings\Settings;
use ClaimBot\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    // Global Settings Object
    $containerBuilder->addDefinitions([
        SettingsInterface::class => function () {
            return new Settings([
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
                'max_batch_size' => 1000, // Claims may contain fewer depending on queue timings etc.
                'messenger' => [
                    // "There should never be more than one messenger:consume command running with the same combination
                    // of stream, group and consumer, or messages could end up being handled more than once."
                    // https://symfony.com/doc/current/messenger.html#redis-transport
                    // Inbound uses Redis in all environments.
                    'inbound_dsn' =>
                        getenv('MESSENGER_INCOMING_TRANSPORT_DSN') . '&consumer=' . uniqid('claimbot-', true),
                    // Outbound uses SQS in deployed AWS environments and Redis locally.
                    'outbound_dsn' => getenv('MESSENGER_OUTBOUND_TRANSPORT_DSN'),
                ],
                'version' => getenv('APP_VERSION'),
            ]);
        }
    ]);
};
