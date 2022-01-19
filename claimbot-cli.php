<?php

declare(strict_types=1);

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

// Allow fewer than 50 messages to be sent in a claim when using SQS, e.g. in Staging
// or Production.
/** @var Settings $settings */
$settings = $psr11App->get(SettingsInterface::class);
$settings->setCurrentBatchSize($settings->get('max_batch_size'));
if ($transport instanceof AmazonSqsTransport) {
    $sqsPending = $transport->getMessageCount();
    if ($sqsPending > 0 && $sqsPending < $settings->get('max_batch_size')) {
        $settings->setCurrentBatchSize($sqsPending);
        $logger->debug(sprintf('Set batch size to reduced current queue backlog %d', $sqsPending));
    } else {
        $logger->debug(sprintf('Did not modify current batch size as %d SQS messages pending', $sqsPending));
    }
} else {
    $logger->debug(sprintf('Did not modify current batch size as transport is %s', get_class($transport)));
}
$psr11App->set(SettingsInterface::class, $settings);

$command = new ConsumeMessagesCommand(
    $psr11App->get(RoutableMessageBus::class),
    $messengerReceiverLocator,
    new EventDispatcher(),
    $psr11App->get(LoggerInterface::class),
    [$messengerReceiverKey],
);

$cliApp->add($command);
$cliApp->run();
