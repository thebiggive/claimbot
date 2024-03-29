<?php

declare(strict_types=1);

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Brick\Postcode\PostcodeFormatter;
use ClaimBot\Claimer;
use ClaimBot\Format;
use ClaimBot\Messenger\Handler\ClaimableDonationHandler;
use ClaimBot\Messenger\OutboundMessageBus;
use ClaimBot\Messenger\Transport\OutboundTransportInterface;
use ClaimBot\Monolog\Handler\ClaimBotHandler;
use ClaimBot\Settings\SettingsInterface;
use DI\Container;
use DI\ContainerBuilder;
use GovTalk\GiftAid\GiftAid;
use Messages\Donation;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransportFactory;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportFactory;
use Symfony\Component\Messenger\Transport\TransportInterface;

return static function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        Claimer::class => function (ContainerInterface $c) {
            return new Claimer($c->get(GiftAid::class), $c->get(LoggerInterface::class));
        },

        CloudWatchLogsClient::class => function (ContainerInterface $c): CloudWatchLogsClient {
            $cloudwatchSettings = $c->get(SettingsInterface::class)->get('logger')['cloudwatch'];
            return new CloudWatchLogsClient([
                'region' => $cloudwatchSettings['region'],
                'version' => 'latest',
                'credentials' => [
                    'key' => $cloudwatchSettings['key'],
                    'secret' => $cloudwatchSettings['secret'],
                ],
            ]);
        },

        GiftAid::class => function (ContainerInterface $c) {
            /**
             * Password must be a govt gateway one in plain text. MD5 was supported before but retired.
             * @link https://www.gov.uk/government/publications/transaction-engine-document-submission-protocol
             */
            $ga = new GiftAid(
                getenv('MAIN_GATEWAY_SENDER_ID'), // TBG's credentials as we're claiming as an Agent.
                getenv('MAIN_GATEWAY_SENDER_PASSWORD'),
                getenv('VENDOR_ID'),
                'The Big Give ClaimBot',
                $c->get(SettingsInterface::class)->get('version'),
                $c->get(SettingsInterface::class)->get('environment') !== 'production',
                null,
                // 'http://host.docker.internal:5665/LTS/LTSPostServlet' // Uncomment to use LTS rather than ETS.
            );
            $ga->setLogger($c->get(LoggerInterface::class));
            $ga->setVendorId(getenv('VENDOR_ID'));

            // Not auth'd with ETS (for now).
            $ga->setAgentDetails(
                getenv('HMRC_AGENT_NO'),
                str_replace('\\s', ' ', getenv('HMRC_AGENT_NAME')),
                Format::agentAddressFromEnvVar(getenv('HMRC_AGENT_ADDRESS')),
                [
                    'telephone' => getenv('HMRC_AGENT_PHONE'),
                ],
                // Outputs e.g. CBv1.1-2022-01-01. Max length for ClaimNo accepted by HMRC is 20 chars.
                'CB' . $c->get(SettingsInterface::class)->get('version') . '-' . date('Y-m-d'),
            );

            // ETS returns an error if you set a GatewayTimestamp – can only use this for LTS.
//            $ga->setTimestamp(new \DateTime());

            $skipCompression = (bool) (getenv('SKIP_PAYLOAD_COMPRESSION') ?? false);
            $ga->setCompress(!$skipCompression);

            return $ga;
        },

        LoggerInterface::class => function (ContainerInterface $c) {
            $loggerSettings = $c->get(SettingsInterface::class)->get('logger');
            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new ClaimBotHandler(
                $c->get(CloudWatchLogsClient::class),
                $loggerSettings,
                $c->get(SettingsInterface::class)->get('environment'),
            );
            $logger->pushHandler($handler);

            return $logger;
        },

        // Used for inbound ready to process donation messages.
        MessageBusInterface::class => static function (ContainerInterface $c): MessageBusInterface {
            return new MessageBus([
                new HandleMessageMiddleware(new HandlersLocator(
                    [
                        Donation::class => [$c->get(ClaimableDonationHandler::class)], // Inbound -> newly processable.
                    ],
                )),
            ]);
        },

        // Used for sending messages to the outbound error queue. This had to be a distinct bus from the above
        // `MessageBusInterface` definition, to avoid a circular dependency via ClaimableDonationHandler which needs
        // the outbound bus to send its errors to.
        OutboundMessageBus::class => static function (ContainerInterface $c): OutboundMessageBus {
            return new OutboundMessageBus([
                new SendMessageMiddleware(new SendersLocator(
                    [
                        Donation::class => [OutboundTransportInterface::class], // Outbound -> donation error queue.
                    ],
                    $c,
                )),
            ]);
        },

        PostcodeFormatter::class => static function (): PostcodeFormatter {
            return new PostcodeFormatter();
        },

        RoutableMessageBus::class => static function (ContainerInterface $c): RoutableMessageBus {
            $busContainer = new Container();
            // Inbound
            $busContainer->set('claimbot.donation.claim', $c->get(MessageBusInterface::class));
            // Outbound
            $busContainer->set('claimbot.donation.result', $c->get(OutboundMessageBus::class));

            return new RoutableMessageBus($busContainer);
        },

        // Outbound messages are donation objects with claim outcome metadata.
        OutboundTransportInterface::class => static function (ContainerInterface $c): TransportInterface {
            $transportFactory = new TransportFactory([
                new AmazonSqsTransportFactory(),
                new RedisTransportFactory(),
            ]);
            return $transportFactory->createTransport(
                $c->get(SettingsInterface::class)->get('messenger')['outbound_dsn'],
                [],
                new PhpSerializer(),
            );
        },

        // Incoming messages are new donations to claim on.
        TransportInterface::class => static function (ContainerInterface $c): TransportInterface {
            $transportFactory = new RedisTransportFactory();
            return $transportFactory->createTransport(
                $c->get(SettingsInterface::class)->get('messenger')['inbound_dsn'],
                [],
                new PhpSerializer(),
            );
        },
    ]);
};
