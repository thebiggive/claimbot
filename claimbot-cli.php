<?php

// todo something like this?! Is it overkill?

declare(strict_types=1);

$psr11App = require __DIR__ . '/bootstrap.php';

use ClaimBot\Commands\ClaimCommand;
use DI\Container;
use Psr\Log\LoggerInterface; // TODO give commands a logger via DI, without Slim `app/` structure?
use Symfony\Component\Console\Application;
use Symfony\Component\Messenger\RoutableMessageBus; // TODO pass thru when consuming real msgs
use Symfony\Component\Messenger\Transport\TransportInterface;

$cliApp = new Application();

//$messengerReceiverKey = 'receiver';
//$messengerReceiverLocator = new Container();
//$messengerReceiverLocator->set($messengerReceiverKey, $psr11App->get(TransportInterface::class));

$commands = [
    new ClaimCommand(),
];

foreach ($commands as $command) {
    // TODO lock the [only] command[s] by default?
//    if ($command instanceof LockingCommand) { // i.e. not Symfony Messenger's built-in consumer.
//        $command->setLockFactory($psr11App->get(LockFactory::class));
//    }

    $cliApp->add($command);
}

$cliApp->run();
