<?php

declare(strict_types=1);

use ClaimBot\Claimer;
use ClaimBot\Commands\Poll;
use ClaimBot\Settings\Settings;
use ClaimBot\Settings\SettingsInterface;
use DI\Container;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransport;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\TransportInterface;

$psr11App = require __DIR__ . '/bootstrap.php';

$cliApp = new Application();

$messengerReceiverKey = 'receiver';
$messengerReceiverLocator = new Container();
/** @var TransportInterface $transport */
$transport = $psr11App->get(TransportInterface::class);
$messengerReceiverLocator->set($messengerReceiverKey, $transport);

$logger = $psr11App->get(LoggerInterface::class);

$consumeCommand = new ConsumeMessagesCommand(
    $psr11App->get(RoutableMessageBus::class),
    $messengerReceiverLocator,
    new EventDispatcher(),
    $psr11App->get(LoggerInterface::class),
    [$messengerReceiverKey],
);

$pollCommand = new Poll(
    $psr11App->get(Claimer::class),
    $psr11App->get(LoggerInterface::class),
    $psr11App->get(SettingsInterface::class),
);

$cliApp->add($consumeCommand);
$cliApp->add($pollCommand);
$cliApp->run();

/**
 * This is essential to ensure that handlers in {@see \ClaimBot\Monolog\Handler\ClaimBotHandler} which batch their
 * logs, e.g. the CloudWatch ones which define a special log stream (rather than going via `StreamHandler` and stdout),
 * send all pending logs at the end of a command run.
 */
$logger->close();
