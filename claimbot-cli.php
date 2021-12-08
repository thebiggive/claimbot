<?php

declare(strict_types=1);

use ClaimBot\Claimer;
use ClaimBot\Commands\ClaimCommand;
use GovTalk\GiftAid\GiftAid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;

$psr11App = require __DIR__ . '/bootstrap.php';

$cliApp = new Application();

$commands = [
    new ClaimCommand(
        $psr11App->get(Claimer::class),
        $psr11App->get(GiftAid::class),
        $psr11App->get(LoggerInterface::class),
    ),
];

foreach ($commands as $command) {
    // TODO lock the [only] command[s] by default?
//    if ($command instanceof LockingCommand) { // i.e. not Symfony Messenger's built-in consumer.
//        $command->setLockFactory($psr11App->get(LockFactory::class));
//    }

    $cliApp->add($command);
}

$cliApp->run();
