<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Console;

use RuntimeException;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Commands that end their process other than by returning, for the end-to-end tests (tests/Console): run inside
 * PHPUnit's own process, they would end the test run.
 */
final class ReportController extends Controller
{
    public function actionCrash(): int
    {
        throw new RuntimeException('The report could not be built.');
    }

    public function actionExit(): void
    {
        exit(0);
    }

    public function actionEnd(): void
    {
        Yii::$app->end();
    }

    public function actionFatal(): void
    {
        // A compile error: PHP stops at once, running only the shutdown functions.
        eval('function yii2_sentry_report() {} function yii2_sentry_report() {}');
    }

    /**
     * Forks a process for part of the work, which ends before the command does.
     */
    public function actionFork(): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            return ExitCode::OSERR;
        }
        if ($pid === 0) {
            exit(0);
        }
        pcntl_waitpid($pid, $status);

        return ExitCode::OK;
    }
}
