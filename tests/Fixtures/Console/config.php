<?php

declare(strict_types=1);

use devnullius\yii2\sentry\Component;
use devnullius\yii2\sentry\LogTarget;
use devnullius\yii2\sentry\tests\Support\RecordingTransport;
use yii\queue\file\Queue;
use yii\queue\LogBehavior;

/**
 * Console application config for each queue worker test scenario: the setup the README recommends (component +
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

    switch ($scenario) {
        case 'default':
            break;
        case 'tracing':
            $sentry['sentrySettings']['traces_sample_rate'] = 1.0;
            break;
        default:
            throw new InvalidArgumentException("Unknown scenario \"$scenario\".");
    }

    return [
        'id' => 'worker-test',
        'basePath' => __DIR__,
        'vendorPath' => dirname(__DIR__, 3) . '/vendor',
        'runtimePath' => sys_get_temp_dir(),
        'bootstrap' => ['sentry', 'log', 'queue'],
        'components' => [
            'sentry' => $sentry,
            'log' => [
                'targets' => [
                    ['class' => LogTarget::class],
                ],
            ],
            'queue' => [
                'class' => Queue::class,
                'path' => $queuePath,
                'as log' => LogBehavior::class,
            ],
        ],
    ];
};
