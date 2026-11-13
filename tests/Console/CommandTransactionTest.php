<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Console;

use devnullius\yii2\sentry\tests\Support\ConsoleProcessTestCase;

/**
 * End-to-end: the transaction of a console command run in a process of its own, ending the ways only a real process
 * shows: an uncaught exception, exit(), Yii::$app->end(), a forked process.
 */
final class CommandTransactionTest extends ConsoleProcessTestCase
{
    public function testACommandThatCrashesIsMarkedAsFailed(): void
    {
        $command = $this->runCommand(['report/crash'], '', 'tracing');

        $this->assertSame(1, $command['exitCode'], $command['log']);
        $this->assertSame(['internal_error'], $this->statuses($command['transactions']), $command['log']);
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
    public function testACommandThatEndsItsProcessItselfIsMarkedAsFailed(string $route): void
    {
        $command = $this->runCommand([$route], '', 'tracing');

        // Its exit code can't be known: it may have failed.
        $this->assertSame(0, $command['exitCode'], $command['log']);
        $this->assertSame(['internal_error'], $this->statuses($command['transactions']), $command['log']);
    }

    /**
     * @requires extension pcntl
     */
    public function testAProcessTheCommandForksDoesNotSendItsTransaction(): void
    {
        $command = $this->runCommand(['report/fork'], '', 'tracing');

        $this->assertSame(0, $command['exitCode'], $command['log']);
        $this->assertSame(['ok'], $this->statuses($command['transactions']), $command['log']);
    }

    /**
     * @param list<array<string, mixed>> $transactions
     *
     * @return list<string|null>
     */
    private function statuses(array $transactions): array
    {
        return array_map(static function (array $transaction): ?string {
            return $transaction['contexts']['trace']['status'] ?? null;
        }, $transactions);
    }
}
