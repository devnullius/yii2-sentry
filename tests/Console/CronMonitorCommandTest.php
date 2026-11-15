<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Console;

use devnullius\yii2\sentry\tests\Support\ConsoleProcessTestCase;

/**
 * End-to-end: listed console commands run as cron runs them, each in a process of its own, ending the ways only a real
 * process shows: an uncaught exception, a fatal error, exit(), Yii::$app->end(), a forked process.
 */
final class CronMonitorCommandTest extends ConsoleProcessTestCase
{
    public function testARunThatCrashesIsReportedAsFailed(): void
    {
        $command = $this->runCommand(['report/crash'], '', 'cron');

        $this->assertSame(1, $command['exitCode'], $command['log']);
        $this->assertSame(['in_progress', 'error'], array_column($command['checkIns'], 'status'), $command['log']);
        // The crash itself is reported too, in the trace of the run: Sentry shows it with the run.
        $this->assertCount(1, $command['events'], $command['log']);
        $this->assertSame('RuntimeException', $command['events'][0]['exception']['values'][0]['type'] ?? null);
        $this->assertSame(
            $command['checkIns'][1]['contexts']['trace']['trace_id'] ?? null,
            $command['events'][0]['contexts']['trace']['trace_id'] ?? null
        );
    }

    public function testARunEndedByAFatalErrorIsReportedAsFailed(): void
    {
        $command = $this->runCommand(['report/fatal'], '', 'cron');

        $this->assertSame(self::FATAL_ERROR_EXIT_CODE, $command['exitCode'], $command['log']);
        $this->assertSame(['in_progress', 'error'], array_column($command['checkIns'], 'status'), $command['log']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endsWithoutAnExitCode(): array
    {
        return [
            'exit()' => ['report/exit'],
            'Yii::$app->end()' => ['report/end'],
        ];
    }

    /**
     * @dataProvider endsWithoutAnExitCode
     */
    public function testARunThatEndsItsProcessItselfIsReportedAsFailed(string $route): void
    {
        $command = $this->runCommand([$route], '', 'cron');

        // Its exit code can't be known: it may have failed.
        $this->assertSame(0, $command['exitCode'], $command['log']);
        $this->assertSame(['in_progress', 'error'], array_column($command['checkIns'], 'status'), $command['log']);
    }

    /**
     * @requires extension pcntl
     */
    public function testAProcessTheCommandForksDoesNotEndTheRun(): void
    {
        $command = $this->runCommand(['report/fork'], '', 'cron');

        // Sentry keeps the first end of a run it receives: the forked process ending first would decide it.
        $this->assertSame(0, $command['exitCode'], $command['log']);
        $this->assertSame(['in_progress', 'ok'], array_column($command['checkIns'], 'status'), $command['log']);
    }

    public function testTheHelpOfACommandIsNotARunOfIt(): void
    {
        $command = $this->runCommand(['report/crash', '--help'], '', 'cron');

        $this->assertSame(0, $command['exitCode'], $command['log']);
        $this->assertSame([], $command['checkIns'], $command['log']);
    }
}
