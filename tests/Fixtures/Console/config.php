<?php

declare(strict_types=1);

use devnullius\yii2\sentry\Component;
use devnullius\yii2\sentry\LogTarget;
use devnullius\yii2\sentry\tests\Fixtures\Console\ReportController;
use devnullius\yii2\sentry\tests\Support\RecordingTransport;
use yii\queue\file\Queue;
use yii\queue\LogBehavior;

/**
 * Console application config for each end-to-end test scenario: the setup the README recommends (component +
 * LogTarget), the package's default integration list, and a file queue with yii2-queue's LogBehavior.
 *
 * @return array<string, mixed>
 */
return static function (string $scenario, string $outFile, string $queuePath): array {
    $sentry = [
        'class' => Component::class,
        'dsn' => 'https://public@sentry.example.com/1',
        'sentrySettings' => ['transport' => new RecordingTransport($outFile)],
    ];
    $logTarget = ['class' => LogTarget::class];

    switch ($scenario) {
        case 'default':
            break;
        case 'existing_hub':
            // The client comes from \Sentry\init() in the yii script, with the SDK's own error listeners.
            $sentry = ['class' => Component::class, 'useExistingHub' => true];
            break;
        case 'existing_hub_errors_excepted':
            $sentry = ['class' => Component::class, 'useExistingHub' => true];
            // A log target that doesn't report what Yii's error handler logs for a fatal error.
            $logTarget['except'] = ['yii\base\ErrorException:*'];
            break;
        case 'tracing':
            $sentry['sentrySettings']['traces_sample_rate'] = 1.0;
            break;
        case 'cron':
            $sentry['cronMonitors'] = ['report/crash', 'report/exit', 'report/end', 'report/fatal', 'report/fork'];
            break;
        default:
            throw new InvalidArgumentException("Unknown scenario \"$scenario\".");
    }

    return [
        'id' => 'worker-test',
        'basePath' => __DIR__,
        'vendorPath' => dirname(__DIR__, 3) . '/vendor',
        'runtimePath' => sys_get_temp_dir(),
        'controllerMap' => ['report' => ReportController::class],
        'bootstrap' => ['sentry', 'log', 'queue'],
        'components' => [
            'sentry' => $sentry,
            'log' => [
                'targets' => [$logTarget],
            ],
            'queue' => [
                'class' => Queue::class,
                'path' => $queuePath,
                'as log' => LogBehavior::class,
            ],
        ],
    ];
};
