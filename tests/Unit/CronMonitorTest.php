<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\tests\Fixtures\Console\HelloController;
use devnullius\yii2\sentry\tests\Support\RecordingTransport;
use devnullius\yii2\sentry\tests\Support\TestCase;
use Sentry\MonitorSchedule;
use Sentry\MonitorScheduleUnit;
use yii\base\ActionEvent;
use yii\base\InvalidConfigException;

/**
 * The runs of listed console commands, reported to Sentry Crons. Runs that end their process, by crashing or with
 * exit(), are tested end to end, in tests/Console.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CronMonitorTest extends TestCase
{
    public function testARunOfAListedCommandIsReportedWhenItStartsAndWhenItEnds(): void
    {
        $this->runCommand('hello/index', ['hello/index']);

        [$start, $end] = $this->checkIns();
        $this->assertSame(['hello-index', 'in_progress', null], [$start['monitor_slug'] ?? null, $start['status'] ?? null, $start['duration'] ?? null]);
        $this->assertSame(['hello-index', 'ok'], [$end['monitor_slug'] ?? null, $end['status'] ?? null]);
        $this->assertSame($start['check_in_id'] ?? null, $end['check_in_id'] ?? null, 'Both are about the same run.');
        $this->assertGreaterThan(0, $end['duration'] ?? 0);
        // The monitor is the one created in Sentry.
        $this->assertArrayNotHasKey('monitor_config', $start);
        $this->assertArrayNotHasKey('monitor_config', $end);
    }

    public function testARunThatFailsIsReportedAsAnError(): void
    {
        $this->runCommand('hello/fail', ['hello/fail']);

        $this->assertSame(['in_progress', 'error'], array_column($this->checkIns(), 'status'));
    }

    public function testARunEndsWithTheExitCodeOfTheResponseItsCommandReturns(): void
    {
        $this->runCommand('hello/respond', ['hello/respond']);

        $this->assertSame(['in_progress', 'error'], array_column($this->checkIns(), 'status'));
    }

    public function testCommandsThatAreNotListedAreNotReported(): void
    {
        $this->runCommand('hello/index', ['hello/fail']);

        $this->assertSame([], $this->checkIns(0));
    }

    public function testACommandCanReportToAMonitorOfAnotherName(): void
    {
        $this->runCommand('hello/index', ['hello/index' => 'nightly-greeting']);

        $this->assertSame(['nightly-greeting', 'nightly-greeting'], array_column($this->checkIns(), 'monitor_slug'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function listedRoutes(): array
    {
        return [
            'listed as it is run' => ['hello', 'hello', 'hello'],
            'listed in full, run by its controller' => ['hello', 'hello/index', 'hello-index'],
            'listed with a leading slash' => ['hello/index', '/hello/index', 'hello-index'],
        ];
    }

    /**
     * @dataProvider listedRoutes
     */
    public function testACommandIsFoundByItsRouteAsRunOrInFull(string $run, string $listed, string $slug): void
    {
        $this->runCommand($run, [$listed]);

        $this->assertSame([$slug, $slug], array_column($this->checkIns(), 'monitor_slug'));
    }

    public function testCommandsThatACommandRunsItselfAreNotRunsOfTheirOwn(): void
    {
        $this->runCommand('hello/chain', ['hello/chain', 'hello/index']);

        $this->assertSame(['hello-chain', 'hello-chain'], array_column($this->checkIns(), 'monitor_slug'));
    }

    public function testARunTheCommandSkipsIsReportedAsSucceeded(): void
    {
        $_SERVER['argv'] = ['yii', 'skipping/index'];
        $app = $this->createConsoleApp(['cronMonitors' => ['skipping/index']]);
        // E.g. because the run before it is still going.
        $app->controllerMap['skipping'] = [
            'class' => HelloController::class,
            'on beforeAction' => static function (ActionEvent $event): void {
                $event->isValid = false;
            },
        ];

        $app->run();

        $this->assertSame(['in_progress', 'ok'], array_column($this->checkIns(), 'status'));
    }

    public function testWithAScheduleTheMonitorIsCreatedOrUpdatedFromTheConfig(): void
    {
        $this->runCommand('hello/index', ['hello/index' => [
            'slug' => 'greeting',
            'schedule' => '0 3 * * *',
            'timezone' => 'Europe/Berlin',
            'checkinMargin' => 5,
            'maxRuntime' => 30,
            'failureIssueThreshold' => 2,
            'recoveryThreshold' => 1,
        ]]);

        foreach ($this->checkIns() as $checkIn) {
            $this->assertSame('greeting', $checkIn['monitor_slug'] ?? null);
            $this->assertSame([
                'schedule' => ['type' => 'crontab', 'value' => '0 3 * * *', 'unit' => ''],
                'checkin_margin' => 5,
                'max_runtime' => 30,
                'timezone' => 'Europe/Berlin',
                'failure_issue_threshold' => 2,
                'recovery_threshold' => 1,
            ], $checkIn['monitor_config'] ?? null);
        }
    }

    public function testASchedulesOtherSettingsAreLeftToSentry(): void
    {
        $this->runCommand('hello/index', ['hello/index' => ['schedule' => '*/10 * * * *']]);

        [$start] = $this->checkIns();
        $this->assertSame('hello-index', $start['monitor_slug'] ?? null);
        $this->assertSame([
            'schedule' => ['type' => 'crontab', 'value' => '*/10 * * * *', 'unit' => ''],
            'checkin_margin' => null,
            'max_runtime' => null,
            'timezone' => null,
            'failure_issue_threshold' => null,
            'recovery_threshold' => null,
        ], $start['monitor_config'] ?? null);
    }

    public function testScheduleSettingsCanBeGivenAsText(): void
    {
        // E.g. from environment variables.
        $this->runCommand('hello/index', ['hello/index' => ['schedule' => '@daily', 'maxRuntime' => '30']]);

        [$start] = $this->checkIns();
        $this->assertSame(['type' => 'crontab', 'value' => '@daily', 'unit' => ''], $start['monitor_config']['schedule'] ?? null);
        $this->assertSame(30, $start['monitor_config']['max_runtime'] ?? null);
    }

    public function testTheScheduleCanBeAnInterval(): void
    {
        $this->runCommand('hello/index', ['hello/index' => ['schedule' => MonitorSchedule::interval(10, MonitorScheduleUnit::minute())]]);

        [$start] = $this->checkIns();
        $this->assertSame(['type' => 'interval', 'value' => 10, 'unit' => 'minute'], $start['monitor_config']['schedule'] ?? null);
    }

    public function testARunIsInTheTraceOfItsCommand(): void
    {
        $this->runCommand('hello/index', ['hello/index'], ['traces_sample_rate' => 1.0]);

        $traceId = $this->transactions()[0]['contexts']['trace']['trace_id'] ?? null;
        $this->assertNotNull($traceId);
        // Sentry shows the errors of the trace with the run.
        $this->assertSame([$traceId, $traceId], array_map(static function (array $checkIn): ?string {
            return $checkIn['contexts']['trace']['trace_id'] ?? null;
        }, $this->checkIns()));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidMonitors(): array
    {
        $schedule = 'The "schedule" of the Sentry cron monitor for "hello/index" must be a cron expression, like "0 3 * * *" or "@daily", or a Sentry\MonitorSchedule.';

        return [
            'not a list' => ['hello/index', 'The Sentry setting "cronMonitors" must list command routes, e.g. ["report/daily"].'],
            'not a route' => [[['hello/index']], 'Sentry cron monitors are listed by command route, e.g. "report/daily".'],
            'a route with a space' => [['hello/index '], 'Sentry cron monitors are listed by command route, e.g. "report/daily".'],
            'a route listed twice' => [['hello/index', '/hello/index' => 'greeting'], 'The Sentry cron monitor for "hello/index" is listed twice.'],
            'neither a slug nor settings' => [['hello/index' => true], 'The Sentry cron monitor for "hello/index" must be given its slug or its settings.'],
            'an empty slug' => [['hello/index' => ['slug' => '']], 'The slug of the Sentry cron monitor for "hello/index" must be a non-empty string.'],
            'a slug Sentry would cut' => [['hello/index' => str_repeat('a', 51)], 'The slug of the Sentry cron monitor for "hello/index" is longer than the 50 characters Sentry keeps.'],
            'a misspelled setting' => [['hello/index' => ['schedule' => '0 3 * * *', 'timeZone' => 'UTC']], 'Unknown Sentry cron monitor setting "timeZone" for "hello/index".'],
            'a setting without a schedule' => [['hello/index' => ['maxRuntime' => 30]], 'The Sentry cron monitor for "hello/index" needs a "schedule" for its "maxRuntime".'],
            'a schedule of another kind' => [['hello/index' => ['schedule' => 3]], $schedule],
            'a schedule in words' => [['hello/index' => ['schedule' => 'every day']], $schedule],
            'an unknown time zone' => [['hello/index' => ['schedule' => '@daily', 'timezone' => 'Europe/Atlantis']], 'The "timezone" of the Sentry cron monitor for "hello/index" must be the name of a time zone, like "Europe/Berlin".'],
            'minutes not as a whole number' => [['hello/index' => ['schedule' => '@daily', 'maxRuntime' => '1h']], 'The "maxRuntime" of the Sentry cron monitor for "hello/index" must be a whole number of at least 1.'],
            'no runs' => [['hello/index' => ['schedule' => '@daily', 'recoveryThreshold' => 0]], 'The "recoveryThreshold" of the Sentry cron monitor for "hello/index" must be a whole number of at least 1.'],
        ];
    }

    /**
     * @dataProvider invalidMonitors
     *
     * @param mixed $monitors
     */
    public function testAMisconfiguredMonitorIsRejected($monitors, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);

        $this->createConsoleApp(['cronMonitors' => $monitors]);
    }

    // --- Helpers --------------------------------------------------------------------------------------------------

    /**
     * Runs a console command of the test application, as cron would.
     *
     * @param array<int|string, mixed> $monitors
     * @param array<string, mixed> $sentrySettings
     */
    private function runCommand(string $route, array $monitors, array $sentrySettings = []): void
    {
        $_SERVER['argv'] = ['yii', $route];
        $app = $this->createConsoleApp(['cronMonitors' => $monitors, 'sentrySettings' => $sentrySettings]);

        $app->run();
    }

    /**
     * @return list<array<string, mixed>> the check-ins sent to Sentry Crons
     */
    private function checkIns(int $expected = 2): array
    {
        $checkIns = RecordingTransport::onlyType($this->transport->payloads, 'check_in');
        $this->assertCount($expected, $checkIns);

        return $checkIns;
    }
}
