<?php

declare(strict_types=1);

use DI\Container;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\TransportInterface;

$psr11App = require __DIR__ . '/bootstrap.php';

$cliApp = new Application();

$messengerReceiverKey = 'receiver';
$messengerReceiverLocator = new Container();
$messengerReceiverLocator->set($messengerReceiverKey, $psr11App->get(TransportInterface::class));

$command = new ConsumeMessagesCommand(
    $psr11App->get(RoutableMessageBus::class),
    $messengerReceiverLocator,
    new EventDispatcher(),
    $psr11App->get(LoggerInterface::class),
    [$messengerReceiverKey],
);

$cliApp->add($command);
$cliApp->run();
