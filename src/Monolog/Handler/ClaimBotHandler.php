<?php

declare(strict_types=1);

namespace ClaimBot\Monolog\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use ClaimBot\Monolog\Processor\JustTransactionIdProcessor;
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
        // Normal error + other runtime logs reach CloudWatch Logs *or* local container output, typically via stdout.
        $streamHandler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
        $streamHandler->pushProcessor(new PsrLogMessageProcessor());

        $govTalkRequestResponseStreamHandler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
        $govTalkRequestResponseStreamHandler->pushProcessor(new JustTransactionIdProcessor());

        $awsRegion = $loggerSettings['cloudwatch']['region'];
        $groupName = "tbg-$environment-$awsRegion-claimbot";

        $retentionDays = 366 * 7; // Keep all claim logs for the full duration HMRC might want info from us.

        // Down from default 10k. Call `close()` on the logger to ensure anything left in the current batch is sent.
        $batchSize = 10;

        $baseRequestHandler = new CloudWatch($awsClient, $groupName, 'gift_aid_requests', $retentionDays, $batchSize);
        $baseResponseHandler = new CloudWatch($awsClient, $groupName, 'gift_aid_responses', $retentionDays, $batchSize);

        $handlers = [
            new GeneralMessageHandlerWrapper($streamHandler), // Exclude full request + response messages.
            new GovTalkRequestResponseHandlerWrapper($govTalkRequestResponseStreamHandler),
            new RequestMessageHandlerWrapper($baseRequestHandler),
            new ResponseMessageHandlerWrapper($baseResponseHandler)
        ];

        parent::__construct($handlers, true);
    }
}
