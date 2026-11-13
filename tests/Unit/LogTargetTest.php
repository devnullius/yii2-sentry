<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\LogTarget;
use devnullius\yii2\sentry\tests\Support\ActiveSession;
use devnullius\yii2\sentry\tests\Support\Identity;
use devnullius\yii2\sentry\tests\Support\TestCase;
use Yii;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LogTargetTest extends TestCase
{
    /**
     * What yii2-queue's LogBehavior logs when a worker ran a job in a process of its own and that process failed: the
     * exception holds everything the process printed (here, to its output and to its error output).
     */
    private const FAILED_JOB_PROCESS_LINE = "[1] app\\jobs\\ChargeJob (attempt: 1, PID: 77) is finished with error: "
        . "Symfony\\Component\\Process\\Exception\\ProcessFailedException: The command \"'/usr/bin/php' '/app/yii' "
        . "'queue/exec' '1' '300' '1' '77' '--color='\" failed.\n\nExit Code: 5(Unknown error)\n\nWorking directory: /app"
        . "\n\nOutput:\n================\nCharging card 4111111111111111\n\n\nError Output:\n================\n"
        . "Token sk_live_123 rejected\n in /app/vendor/yiisoft/yii2-queue/src/cli/Command.php:195\nStack trace:\n"
        . "#0 /app/vendor/yiisoft/yii2-queue/src/cli/Queue.php(146): yii\\queue\\cli\\Command->handleMessage(1, "
        . "'O:22:\"app\\\\jobs\\\\Ch...', 300, 1)\n#1 {main}.";

    /**
     * What yii2-queue's LogBehavior logs for a stored job it can't read as a job, here because the job's class was
     * renamed after the job was queued: the exception holds a dump of the job, with all its data.
     */
    private const UNREADABLE_JOB_LINE = "[1] unknown job (attempt: 1) is finished with error: yii\\queue\\InvalidJobException: "
        . "Job must be a JobInterface instance instead of __PHP_Incomplete_Class#1\n(\n"
        . "    [__PHP_Incomplete_Class_Name] => 'app\\\\jobs\\\\ChargeJob'\n    [orderId] => 1001\n"
        . "    [email] => 'bob@example.com'\n    [cardNumber] => '4111111111111111'\n). in /app/vendor/yiisoft/yii2-queue/src/Queue.php:282\n"
        . "Stack trace:\n#0 /app/vendor/yiisoft/yii2-queue/src/Queue.php(229): yii\\queue\\Queue->unserializeMessage('O:22:\"app\\\\jobs\\\\Ch...')\n#1 {main}.";

    public function testLogLinesDoNotContainTheClientIpUserIdOrSessionIdByDefault(): void
    {
        $this->appWithLogTarget();

        $logs = $this->exportTwoMessages();

        $this->assertStringContainsString('first problem', $logs);
        $this->assertStringNotContainsString(ActiveSession::ID, $logs, 'A session ID in Sentry allows session hijacking.');
        $this->assertStringNotContainsString('203.0.113.10', $logs);
        $this->assertStringNotContainsString('[42]', $logs);
    }

    public function testLogLinesKeepYiisPrefixWhenSendingPiiIsEnabled(): void
    {
        $this->appWithLogTarget(['send_default_pii' => true]);

        $logs = $this->exportTwoMessages();

        $this->assertStringContainsString('[203.0.113.10][42][' . ActiveSession::ID . ']', $logs);
    }

    public function testACustomPrefixIsRespected(): void
    {
        $this->appWithLogTarget([], ['prefix' => static function (): string {
            return '[custom]';
        }]);

        $logs = $this->exportTwoMessages();

        $this->assertStringContainsString('[custom]', $logs);
    }

    public function testWhatAFailedQueueJobsProcessPrintedIsNotSent(): void
    {
        $this->appWithLogTarget();

        Yii::error(self::FAILED_JOB_PROCESS_LINE, 'yii\queue\Queue');
        Yii::getLogger()->flush(true);

        $event = $this->singleEvent();
        $expected = substr(self::FAILED_JOB_PROCESS_LINE, 0, (int)strpos(self::FAILED_JOB_PROCESS_LINE, "\n\nOutput:")) . "\n\nOutput: [not sent]";
        $this->assertSame($expected, $event['message'] ?? null);
        $this->assertSame($expected, $event['extra']['real_body'] ?? null);
        $this->assertStringEndsWith("Working directory: /app\n\nOutput: [not sent]", $expected);
        $sent = (string)json_encode($event);
        $this->assertStringNotContainsString('4111111111111111', $sent);
        $this->assertStringNotContainsString('sk_live_123', $sent);
    }

    public function testWhatAFailedQueueJobsProcessPrintedIsNotSentWithTheOtherLogLinesEither(): void
    {
        $this->appWithLogTarget();

        Yii::error(self::FAILED_JOB_PROCESS_LINE, 'yii\queue\Queue');
        Yii::error('a later problem', 'app');
        Yii::getLogger()->flush(true);

        $event = $this->singleEvent();
        $this->assertSame('a later problem', $event['message'] ?? null);
        $sent = (string)json_encode($event);
        $this->assertStringContainsString('Output: [not sent]', $sent);
        $this->assertStringNotContainsString('4111111111111111', $sent);
        $this->assertStringNotContainsString('sk_live_123', $sent);
    }

    public function testTheDataOfAQueueJobThatCannotBeReadIsNotSent(): void
    {
        $this->appWithLogTarget();

        Yii::error(self::UNREADABLE_JOB_LINE, 'yii\queue\Queue');
        Yii::getLogger()->flush(true);

        $event = $this->singleEvent();
        $this->assertSame(
            '[1] unknown job (attempt: 1) is finished with error: yii\queue\InvalidJobException: Job must be a JobInterface '
                . 'instance instead of __PHP_Incomplete_Class (app\jobs\ChargeJob).',
            $event['message'] ?? null
        );
        $sent = (string)json_encode($event);
        $this->assertStringNotContainsString('bob@example.com', $sent);
        $this->assertStringNotContainsString('4111111111111111', $sent);
    }

    public function testYiisWarningAboutAnInvalidAuthKeyIsSentWithoutTheKeyUserIdAndIpAddress(): void
    {
        $this->appWithLogTarget();

        Yii::warning("Invalid cookie auth key attempted for user '42' from 203.0.113.10: k3y-from-the-cookie", 'yii\web\User::getIdentityAndDurationFromCookie');
        Yii::getLogger()->flush(true);

        $event = $this->singleEvent();
        $expected = "Invalid cookie auth key attempted for user '[Filtered PII]' from [Filtered PII]: [Filtered PII]";
        $this->assertSame($expected, $event['message'] ?? null);
        $this->assertSame($expected, $event['extra']['real_body'] ?? null);
    }

    public function testYiisWarningAboutAnInvalidAuthKeyIsScrubbedWithTheOtherLogLinesToo(): void
    {
        $this->appWithLogTarget();

        Yii::warning("Invalid session auth key attempted for user '42' from 2001:db8::1: k3y-from-the-session", 'yii\web\User::renewAuthStatus');
        Yii::error('a later problem', 'app');
        Yii::getLogger()->flush(true);

        $event = $this->singleEvent();
        $this->assertSame('a later problem', $event['message'] ?? null);
        $logs = implode("\n", (array)($event['extra']['logs'] ?? []));
        $this->assertStringContainsString("Invalid session auth key attempted for user '[Filtered PII]' from [Filtered PII]: [Filtered PII]", $logs);
        $this->assertStringNotContainsString('k3y-from-the-session', $logs);
        $this->assertStringNotContainsString('2001:db8::1', $logs);
    }

    public function testWithSendingPiiEnabledTheAuthKeyIsStillLeftOut(): void
    {
        $this->appWithLogTarget(['send_default_pii' => true]);

        Yii::warning("Invalid cookie auth key attempted for user '42' from 203.0.113.10: k3y", 'yii\web\User::getIdentityAndDurationFromCookie');
        Yii::getLogger()->flush(true);

        $this->assertSame(
            "Invalid cookie auth key attempted for user '42' from 203.0.113.10: [Filtered PII]",
            $this->singleEvent()['message'] ?? null
        );
    }

    public function testThePasswordInTheDsnOfAFailedConnectionIsNotSent(): void
    {
        $this->appWithLogTarget(['send_default_pii' => true]);

        // Yii's warning about a replica, or a master, of the connection's pool that couldn't be opened.
        Yii::warning('Connection (pgsql:host=replica;password=db-secret;dbname=app) failed: SQLSTATE[08006] [7] connection refused', 'yii\db\Connection::openFromPoolSequentially');
        Yii::getLogger()->flush(true);

        $this->assertSame(
            'Connection (pgsql:host=replica;password=[Filtered PII];dbname=app) failed: SQLSTATE[08006] [7] connection refused',
            $this->singleEvent()['message'] ?? null
        );
    }

    public function testOnlyYiisOwnWarningsAreScrubbed(): void
    {
        $this->appWithLogTarget();
        $line = "Invalid cookie auth key attempted for user '42' from 203.0.113.10: k3y";

        Yii::warning($line, 'app');
        Yii::getLogger()->flush(true);

        $this->assertSame($line, $this->singleEvent()['message'] ?? null);
    }

    public function testTheApplicationsOwnLinesAreSentAsWritten(): void
    {
        $this->appWithLogTarget();

        Yii::error(self::FAILED_JOB_PROCESS_LINE, 'app');
        Yii::error(self::UNREADABLE_JOB_LINE, 'app');
        Yii::getLogger()->flush(true);

        $event = $this->singleEvent();
        $this->assertSame(self::UNREADABLE_JOB_LINE, $event['message'] ?? null);
        $this->assertStringContainsString('Charging card 4111111111111111', implode("\n", (array)($event['extra']['logs'] ?? [])));
    }

    /**
     * @param array<string, mixed> $sentrySettings
     * @param array<string, mixed> $target
     */
    private function appWithLogTarget(array $sentrySettings = [], array $target = []): void
    {
        $app = $this->createWebApp(['sentrySettings' => $sentrySettings], [], [
            'session' => ['class' => ActiveSession::class],
            'log' => ['targets' => ['sentry' => array_merge([
                'class' => LogTarget::class,
                'levels' => ['error', 'warning'],
            ], $target)]],
        ]);
        $app->getLog();
        $app->getUser()->setIdentity(new Identity());
        $app->getSession();
    }

    private function exportTwoMessages(): string
    {
        Yii::warning('first problem', 'app');
        Yii::error('second problem', 'app');
        Yii::getLogger()->flush(true);

        return implode("\n", (array)($this->singleEvent()['extra']['logs'] ?? []));
    }
}
