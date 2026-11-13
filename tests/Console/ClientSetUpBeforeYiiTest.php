<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Console;

use devnullius\yii2\sentry\tests\Support\ConsoleProcessTestCase;
use Sentry\Exception\FatalErrorException;
use yii\base\ErrorException;

/**
 * End-to-end: a console command of an application that sets up Sentry with \Sentry\init() before Yii starts, so that
 * the SDK's own error listeners are active, and the component reuses that client.
 */
final class ClientSetUpBeforeYiiTest extends ConsoleProcessTestCase
{
    public function testAFatalErrorIsReportedOnceByYiisErrorHandler(): void
    {
        $command = $this->runCommand(['report/fatal'], '', 'existing_hub');

        $this->assertSame(1, $command['exitCode'], $command['log']);
        // The SDK's fatal-error listener reports it as well, before Yii's error handler does.
        $this->assertCount(1, $command['events'], $command['log']);
        $event = $command['events'][0];
        $exception = $event['exception']['values'][0] ?? [];
        $this->assertSame(ErrorException::class, $exception['type'] ?? null);
        $this->assertFalse($exception['mechanism']['handled'] ?? null);
        // Yii's report comes with the log messages before the error.
        $this->assertContains('Building the report', array_column($event['breadcrumbs']['values'] ?? [], 'message'));
    }

    public function testWithoutALogTargetThatReportsItTheSdksOwnReportIsKept(): void
    {
        $command = $this->runCommand(['report/fatal'], '', 'existing_hub_errors_excepted');

        $this->assertSame(1, $command['exitCode'], $command['log']);
        $this->assertCount(1, $command['events'], $command['log']);
        $this->assertSame(FatalErrorException::class, $command['events'][0]['exception']['values'][0]['type'] ?? null);
    }
}
