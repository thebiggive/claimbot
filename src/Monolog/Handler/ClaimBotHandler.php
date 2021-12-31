<?php

declare(strict_types=1);

namespace ClaimBot\Monolog\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Maxbanton\Cwh\Handler\CloudWatch;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

class ClaimBotHandler extends GroupHandler
{
    public function __construct(
        CloudWatchLogsClient $awsClient,
        array $loggerSettings,
        string $environment,
    ) {
        // Normal error + other runtime logs reach CloudWatch Logs *or* local container output via stdout.
        $streamHandler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
        $streamHandler->pushProcessor(new PsrLogMessageProcessor());

        $awsRegion = $loggerSettings['cloudwatch']['region'];
        $groupName = "tbg-$environment-$awsRegion-claimbot";

        $baseRequestHandler = new CloudWatch($awsClient, $groupName, 'gift_aid_requests');
        $baseResponseHandler = new CloudWatch($awsClient, $groupName, 'gift_aid_responses');

        $handlers = [
            $streamHandler,
            new RequestMessageHandlerWrapper($baseRequestHandler),
            new ResponseMessageHandlerWrapper($baseResponseHandler)
        ];

        parent::__construct($handlers, true);
    }
}
