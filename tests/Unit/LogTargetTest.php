<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\LogTarget;
use devnullius\yii2\sentry\tests\Support\ActiveSession;
use devnullius\yii2\sentry\tests\Support\Identity;
use devnullius\yii2\sentry\tests\Support\TestCase;
use RuntimeException;
use Yii;
use yii\log\Logger;
use yii\web\Application;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LogTargetTest extends TestCase
{
    // --- Events -------------------------------------------------------------------------------------------------

    public function testEachLoggedErrorAndWarningBecomesItsOwnEvent(): void
    {
        $this->appWithLogTarget();

        Yii::error('first problem', 'app');
        Yii::warning('second problem', 'app');
        Yii::error('third problem', 'app');
        Yii::getLogger()->flush(true);

        $events = $this->transport->events();
        $this->assertSame(['first problem', 'second problem', 'third problem'], array_column($events, 'message'));
        $this->assertSame(['error', 'warning', 'error'], array_column($events, 'level'));
    }

    public function testOnlyErrorsAndWarningsBecomeEventsByDefault(): void
    {
        $this->appWithLogTarget(); // no "levels" configured: for Yii alone that would mean every level

        Yii::info('just information', 'app');
        Yii::getLogger()->flush(true);

        $this->assertSame([], $this->transport->events(), 'An info message must not become an event.');
    }

    public function testTheEventTimeIsWhenTheMessageWasLogged(): void
    {
        $this->appWithLogTarget();

        // Logs are exported in a batch, possibly much later than they were written.
        Yii::getLogger()->messages[] = ['logged a while ago', Logger::LEVEL_ERROR, 'app', 1700000000.25, [], 0];
        Yii::getLogger()->flush(true);

        $this->assertEqualsWithDelta(1700000000.25, $this->singleEvent()['timestamp'] ?? 0, 0.001);
    }

    public function testAnExceptionThatReachedTheErrorHandlerIsUnhandled(): void
    {
        $app = $this->appWithLogTarget();
        $exception = new RuntimeException('crash');
        $app->getErrorHandler()->exception = $exception; // what Yii does before logging an uncaught exception

        Yii::error($exception, 'RuntimeException');
        Yii::getLogger()->flush(true);

        $this->assertFalse($this->lastException($this->singleEvent())['mechanism']['handled'] ?? null);
    }

    public function testAnExceptionLoggedByTheApplicationStaysHandled(): void
    {
        $this->appWithLogTarget();

        Yii::error(new RuntimeException('caught and logged'), 'app');
        Yii::getLogger()->flush(true);

        $this->assertTrue($this->lastException($this->singleEvent())['mechanism']['handled'] ?? null);
    }

    public function testAnExceptionLoggedAsAWarningIsAWarning(): void
    {
        $this->appWithLogTarget();

        Yii::warning(new RuntimeException('minor'), 'app');
        Yii::getLogger()->flush(true);

        $this->assertSame('warning', $this->singleEvent()['level'] ?? null);
    }

    public function testTheLogCategoryIsTheEventsLogger(): void
    {
        $this->appWithLogTarget();

        Yii::error('payment failed', 'payments');
        Yii::getLogger()->flush(true);

        $this->assertSame('payments', $this->singleEvent()['logger'] ?? null);
    }

    public function testTheLoggerNameCanBeTurnedOff(): void
    {
        $this->appWithLogTarget(['categoryAsLogger' => false]);

        Yii::error('payment failed', 'payments');
        Yii::getLogger()->flush(true);

        $this->assertArrayNotHasKey('logger', $this->singleEvent());
    }

    public function testYiiLogTracesBecomeTheEventsStackTrace(): void
    {
        $this->appWithLogTarget();
        Yii::getLogger()->traceLevel = 3;

        $this->logAnError();
        Yii::getLogger()->flush(true);

        $files = array_map('basename', array_column($this->singleEvent()['stacktrace']['frames'] ?? [], 'filename'));
        $this->assertContains('LogTargetTest.php', $files);
    }

    public function testStackTracesFromLogTracesCanBeTurnedOff(): void
    {
        $this->appWithLogTarget(['stackTraces' => false]);
        Yii::getLogger()->traceLevel = 3;

        $this->logAnError();
        Yii::getLogger()->flush(true);

        $this->assertArrayNotHasKey('stacktrace', $this->singleEvent());
    }

    // --- Breadcrumbs --------------------------------------------------------------------------------------------

    public function testEarlierLogMessagesAreAttachedAsBreadcrumbs(): void
    {
        $this->appWithLogTarget();

        Yii::info('user opened the cart', 'app');
        Yii::warning('coupon expired', 'app');
        Yii::error('payment failed', 'payments');
        Yii::getLogger()->flush(true);

        [$warning, $error] = $this->transport->events();
        $this->assertSame([['info', 'app', 'user opened the cart']], $this->breadcrumbs($warning));
        $this->assertSame(
            [['info', 'app', 'user opened the cart'], ['warning', 'app', 'coupon expired']],
            $this->breadcrumbs($error)
        );
    }

    public function testBreadcrumbsCanBeTurnedOff(): void
    {
        $this->appWithLogTarget(['breadcrumbs' => false]);

        Yii::info('user opened the cart', 'app');
        Yii::error('payment failed', 'app');
        Yii::getLogger()->flush(true);

        $this->assertSame([], $this->breadcrumbs($this->singleEvent()));
    }

    public function testProfilingMarkersAreLeftOutOfBreadcrumbsByDefault(): void
    {
        $this->appWithLogTarget();

        Yii::beginProfile('render invoice', 'app');
        Yii::endProfile('render invoice', 'app');
        Yii::error('payment failed', 'app');
        Yii::getLogger()->flush(true);

        $this->assertSame([], $this->breadcrumbs($this->singleEvent()));
    }

    public function testProfilingMarkersCanBeBreadcrumbs(): void
    {
        $this->appWithLogTarget(['breadcrumbLevels' => ['error', 'warning', 'info', 'trace', 'profile']]);

        Yii::beginProfile('render invoice', 'app');
        Yii::endProfile('render invoice', 'app');
        Yii::error('payment failed', 'app');
        Yii::getLogger()->flush(true);

        $this->assertSame(['render invoice', 'render invoice'], array_column($this->breadcrumbs($this->singleEvent()), 2));
    }

    public function testSqlLogLinesAreQueryBreadcrumbsWithTheirValuesMasked(): void
    {
        $this->appWithLogTarget();

        Yii::info("SELECT * FROM `user` WHERE `email`='ann@example.com' AND `id`=42", 'yii\db\Command::query');
        Yii::info("UPDATE \"order\" SET \"note\"='it''s paid', \"total\"=19.90 WHERE \"id\" IN (7, 8)", 'yii\db\Command::execute');
        Yii::error('payment failed', 'app');
        Yii::getLogger()->flush(true);

        $this->assertSame([
            ['info', 'db.sql.query', 'SELECT * FROM `user` WHERE `email`=? AND `id`=?'],
            ['info', 'db.sql.query', 'UPDATE "order" SET "note"=?, "total"=? WHERE "id" IN (?, ?)'],
        ], $this->breadcrumbs($this->singleEvent()));
    }

    public function testSqlValuesCanBeKept(): void
    {
        $this->appWithLogTarget(['maskSqlValues' => false]);
        $sql = "SELECT * FROM `user` WHERE `email`='ann@example.com'";

        Yii::info($sql, 'yii\db\Command::query');
        Yii::error('payment failed', 'app');
        Yii::getLogger()->flush(true);

        $this->assertSame([['info', 'db.sql.query', $sql]], $this->breadcrumbs($this->singleEvent()));
    }

    // --- Sentry Logs --------------------------------------------------------------------------------------------

    public function testSentryLogsAreOffByDefault(): void
    {
        $this->appWithLogTarget();

        Yii::info('user opened the cart', 'app');
        Yii::error('payment failed', 'app');
        Yii::getLogger()->flush(true);

        $this->assertSame([], $this->transport->logs());
    }

    public function testMessagesCanBeForwardedToSentryLogs(): void
    {
        $this->appWithLogTarget(['sentryLogs' => true]);

        Yii::info('user opened the cart', 'app');
        Yii::error('payment failed', 'payments');
        Yii::getLogger()->flush(true);

        $entries = $this->transport->logs();
        $this->assertSame(['user opened the cart', 'payment failed'], array_column($entries, 'body'));
        $this->assertSame(['info', 'error'], array_column($entries, 'level'));
        $this->assertSame('payments', $entries[1]['attributes']['yii.category']['value'] ?? null);
        $this->assertSame('auto.log.yii2', $entries[1]['attributes']['sentry.origin']['value'] ?? null);
    }

    public function testSqlSentToSentryLogsIsMaskedToo(): void
    {
        $this->appWithLogTarget(['sentryLogs' => true]);

        Yii::info("SELECT * FROM `user` WHERE `email`='ann@example.com'", 'yii\db\Command::query');
        Yii::getLogger()->flush(true);

        $this->assertSame(['SELECT * FROM `user` WHERE `email`=?'], array_column($this->transport->logs(), 'body'));
    }

    // --- Version 4 behavior, kept behind the "aggregate" option -------------------------------------------------

    public function testAggregateModeSendsOneEventPerExportLikeVersion4(): void
    {
        $this->appWithLogTarget(['aggregate' => true]);

        Yii::warning('first problem', 'app');
        Yii::error('second problem', 'app');
        Yii::getLogger()->flush(true);

        $event = $this->singleEvent();
        $this->assertSame('second problem', $event['message'] ?? null);
        $this->assertStringContainsString('first problem', implode("\n", (array)($event['extra']['logs'] ?? [])));
    }

    public function testAggregatedLogLinesDoNotContainTheClientIpUserIdOrSessionIdByDefault(): void
    {
        $this->appForPrefixTests();

        $logs = $this->exportTwoMessagesAggregated();

        $this->assertStringContainsString('first problem', $logs);
        $this->assertStringNotContainsString(ActiveSession::ID, $logs, 'A session ID in Sentry allows session hijacking.');
        $this->assertStringNotContainsString('203.0.113.10', $logs);
        $this->assertStringNotContainsString('[42]', $logs);
    }

    public function testAggregatedLogLinesKeepYiisPrefixWhenSendingPiiIsEnabled(): void
    {
        $this->appForPrefixTests(['send_default_pii' => true]);

        $logs = $this->exportTwoMessagesAggregated();

        $this->assertStringContainsString('[203.0.113.10][42][' . ActiveSession::ID . ']', $logs);
    }

    /**
     * With the SDK's `data_collection` option, the SDK ignores `send_default_pii`: its `user_info` decides.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function dataCollectionPolicies(): array
    {
        return [
            'user info collected' => [['data_collection' => ['user_info' => true]], true],
            'user info not collected, despite send_default_pii' => [['send_default_pii' => true, 'data_collection' => ['user_info' => false]], false],
        ];
    }

    /**
     * @dataProvider dataCollectionPolicies
     *
     * @param array<string, mixed> $sentrySettings
     */
    public function testWithTheDataCollectionOptionYiisPrefixFollowsItsUserInfo(array $sentrySettings, bool $kept): void
    {
        $this->appForPrefixTests($sentrySettings);

        $logs = $this->exportTwoMessagesAggregated();

        if ($kept) {
            $this->assertStringContainsString('[203.0.113.10][42]', $logs);
        } else {
            $this->assertStringNotContainsString('203.0.113.10', $logs);
            $this->assertStringNotContainsString('[42]', $logs);
        }
    }

    public function testACustomPrefixIsRespected(): void
    {
        $this->appForPrefixTests([], ['prefix' => static function (): string {
            return '[custom]';
        }]);

        $logs = $this->exportTwoMessagesAggregated();

        $this->assertStringContainsString('[custom]', $logs);
    }

    // --- Helpers ------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $sentrySettings
     */
    private function appWithLogTarget(array $target = [], array $sentrySettings = []): Application
    {
        $app = $this->createWebApp(['sentrySettings' => $sentrySettings], [], [
            'log' => ['targets' => ['sentry' => array_merge(['class' => LogTarget::class], $target)]],
        ]);
        $app->getLog();

        return $app;
    }

    /**
     * @param array<string, mixed> $sentrySettings
     * @param array<string, mixed> $target
     */
    private function appForPrefixTests(array $sentrySettings = [], array $target = []): void
    {
        $app = $this->createWebApp(['sentrySettings' => $sentrySettings], [], [
            'session' => ['class' => ActiveSession::class],
            'log' => ['targets' => ['sentry' => array_merge([
                'class' => LogTarget::class,
                'levels' => ['error', 'warning'],
                'aggregate' => true,
            ], $target)]],
        ]);
        $app->getLog();
        $app->getUser()->setIdentity(new Identity());
        $app->getSession();
    }

    private function exportTwoMessagesAggregated(): string
    {
        Yii::warning('first problem', 'app');
        Yii::error('second problem', 'app');
        Yii::getLogger()->flush(true);

        return implode("\n", (array)($this->singleEvent()['extra']['logs'] ?? []));
    }

    private function logAnError(): void
    {
        Yii::error('failed somewhere deep', 'app');
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return list<array{0: string, 1: string, 2: string}> [level, category, message] of each breadcrumb
     */
    private function breadcrumbs(array $event): array
    {
        return array_map(static function (array $crumb): array {
            return [$crumb['level'] ?? '', $crumb['category'] ?? '', $crumb['message'] ?? ''];
        }, $event['breadcrumbs']['values'] ?? []);
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function lastException(array $event): array
    {
        $values = $event['exception']['values'] ?? [];

        return $values !== [] ? end($values) : [];
    }
}
