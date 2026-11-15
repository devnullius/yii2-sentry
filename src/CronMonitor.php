<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use DateTimeZone;
use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\State\HubInterface;
use Throwable;
use Yii;
use yii\base\Action;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Controller;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApplication;

/**
 * Reports the runs of the console commands listed in [[Component::$cronMonitors]] to Sentry Crons: a check-in when a
 * run starts, and one when it ends.
 *
 * @internal
 */
final class CronMonitor
{
    /**
     * The settings that only apply with a schedule: Sentry then creates or updates the monitor from them.
     */
    private const SCHEDULE_SETTINGS = ['timezone', 'checkinMargin', 'maxRuntime', 'failureIssueThreshold', 'recoveryThreshold'];
    /**
     * The settings that are whole numbers, of minutes or runs, as Sentry takes them.
     */
    private const WHOLE_NUMBER_SETTINGS = ['checkinMargin', 'maxRuntime', 'failureIssueThreshold', 'recoveryThreshold'];
    /**
     * The schedules Sentry takes by name, besides cron expressions of five fields.
     */
    private const NAMED_SCHEDULES = ['@yearly', '@annually', '@monthly', '@weekly', '@daily', '@hourly'];
    /**
     * The longest slug Sentry keeps: it cuts longer ones, which could make two monitors one.
     */
    private const MAX_SLUG_LENGTH = 50;

    /**
     * @var HubInterface
     */
    private $hub;
    /**
     * @var array<string, array{0: string, 1: MonitorConfig|null}> the slug and the config of each monitor, by route
     */
    private $monitors;
    /**
     * @var callable(string, string, callable): void
     */
    private $listen;
    /**
     * @var array{slug: string, config: MonitorConfig|null, start: float, pid: int|false, id: string|null}|null the run
     * being reported, and the process that runs it
     */
    private $run;
    /**
     * @var bool
     */
    private $shutdownRegistered = false;

    /**
     * @param array<string, array{0: string, 1: MonitorConfig|null}> $monitors as [[monitors()]] returns them
     * @param callable(string, string, callable): void $listen registers a class-level event handler
     */
    public function __construct(HubInterface $hub, array $monitors, callable $listen)
    {
        $this->hub = $hub;
        $this->monitors = $monitors;
        $this->listen = $listen;
    }

    /**
     * Reads the [[Component::$cronMonitors]] setting.
     *
     * @param mixed $setting
     *
     * @return array<string, array{0: string, 1: MonitorConfig|null}> the slug and the config of each monitor, by route
     *
     * @throws InvalidConfigException on a monitor that isn't set up right, e.g. with a misspelled setting
     */
    public static function monitors($setting): array
    {
        if (!is_array($setting)) {
            throw new InvalidConfigException('The Sentry setting "cronMonitors" must list command routes, e.g. ["report/daily"].');
        }

        $monitors = [];
        foreach ($setting as $key => $value) {
            // 'report/daily', 'report/daily' => 'daily-report', or 'report/daily' => [settings]
            $route = is_int($key) ? $value : $key;
            if (!is_string($route) || trim($route, '/') === '' || preg_match('/\s/', $route) === 1) {
                throw new InvalidConfigException('Sentry cron monitors are listed by command route, e.g. "report/daily".');
            }
            $route = trim($route, '/');
            if (isset($monitors[$route])) {
                throw new InvalidConfigException(sprintf('The Sentry cron monitor for "%s" is listed twice.', $route));
            }

            $settings = is_int($key) ? [] : (is_string($value) ? ['slug' => $value] : $value);
            if (!is_array($settings)) {
                throw new InvalidConfigException(sprintf(
                    'The Sentry cron monitor for "%s" must be given its slug or its settings.',
                    $route
                ));
            }
            foreach (array_keys($settings) as $name) {
                if ($name !== 'slug' && $name !== 'schedule' && !in_array($name, self::SCHEDULE_SETTINGS, true)) {
                    throw new InvalidConfigException(sprintf('Unknown Sentry cron monitor setting "%s" for "%s".', $name, $route));
                }
            }

            $monitors[$route] = [self::slug($route, $settings), self::monitorConfig($route, $settings)];
        }

        return $monitors;
    }

    /**
     * Starts watching for the run of the command, while the console application bootstraps.
     */
    public function start(ConsoleApplication $app): void
    {
        $result = new CommandResult($app, $this->listen);

        ($this->listen)(Controller::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event) use ($app): void {
            $this->beforeAction($event->action, $app);
        });
        ($this->listen)(ConsoleApplication::class, Application::EVENT_AFTER_REQUEST, function () use ($result): void {
            $this->finish($result->failed() ? CheckInStatus::error() : CheckInStatus::ok());
        });
    }

    /**
     * Reports the end of a run that didn't return its exit code: it crashed, or ended its process itself, e.g. with
     * exit(), so that its exit code can't be known. Called when PHP stops, and when the next application is
     * bootstrapped in the same process.
     */
    public function end(): void
    {
        $this->finish(CheckInStatus::error());
    }

    private function beforeAction(Action $action, ConsoleApplication $app): void
    {
        // Only the command that was run, not the ones it runs itself.
        if ($this->run !== null || $action !== $app->requestedAction) {
            return;
        }

        $monitor = $this->monitorOf($action, $app);
        if ($monitor === null) {
            return;
        }

        [$slug, $config] = $monitor;
        $this->run = [
            'slug' => $slug,
            'config' => $config,
            'start' => microtime(true),
            'pid' => getmypid(),
            'id' => null,
        ];
        $this->registerShutdown();
        $this->run['id'] = $this->hub->captureCheckIn($slug, CheckInStatus::inProgress(), null, $config);
    }

    /**
     * @return array{0: string, 1: MonitorConfig|null}|null the slug and the config of the monitor of the action
     */
    private function monitorOf(Action $action, ConsoleApplication $app): ?array
    {
        $route = $action->getUniqueId();
        if (isset($this->monitors[$route])) {
            return $this->monitors[$route];
        }

        // Listed as it was run, e.g. "report" for "report/index"; not for a command run in its place, like "help" for
        // "report/daily --help".
        $requested = trim((string)$app->requestedRoute, '/');

        return $requested !== '' && strpos($route, $requested . '/') === 0 ? $this->monitors[$requested] ?? null : null;
    }

    private function finish(CheckInStatus $status): void
    {
        $run = $this->run;
        // A process the command forked, e.g. to do part of its work, doesn't end the run.
        if ($run === null || $run['pid'] !== getmypid()) {
            return;
        }
        $this->run = null;

        $this->hub->captureCheckIn($run['slug'], $status, microtime(true) - $run['start'], $run['config'], $run['id']);
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            try {
                $this->end();
            } catch (Throwable $exception) {
                Yii::warning('Unable to report the end of the command to Sentry Crons: ' . $exception, self::class);
            }
        });
    }

    /**
     * @param array<int|string, mixed> $settings
     *
     * @throws InvalidConfigException
     */
    private static function slug(string $route, array $settings): string
    {
        // As Sentry would name it: "report/daily" is "report-daily".
        $slug = $settings['slug'] ?? str_replace('/', '-', $route);
        if (!is_string($slug) || $slug === '') {
            throw new InvalidConfigException(sprintf(
                'The slug of the Sentry cron monitor for "%s" must be a non-empty string.',
                $route
            ));
        }
        if (strlen($slug) > self::MAX_SLUG_LENGTH) {
            throw new InvalidConfigException(sprintf(
                'The slug of the Sentry cron monitor for "%s" is longer than the %d characters Sentry keeps.',
                $route,
                self::MAX_SLUG_LENGTH
            ));
        }

        return $slug;
    }

    /**
     * @param array<int|string, mixed> $settings
     *
     * @return MonitorConfig|null what Sentry creates or updates the monitor from; none for a monitor set up in Sentry
     *
     * @throws InvalidConfigException
     */
    private static function monitorConfig(string $route, array $settings): ?MonitorConfig
    {
        $schedule = $settings['schedule'] ?? null;
        if ($schedule === null) {
            foreach (self::SCHEDULE_SETTINGS as $name) {
                if (isset($settings[$name])) {
                    throw new InvalidConfigException(sprintf(
                        'The Sentry cron monitor for "%s" needs a "schedule" for its "%s".',
                        $route,
                        $name
                    ));
                }
            }

            return null;
        }

        if (is_string($schedule) && self::isCronExpression($schedule)) {
            $schedule = MonitorSchedule::crontab(trim($schedule));
        } elseif (!$schedule instanceof MonitorSchedule) {
            throw new InvalidConfigException(sprintf(
                'The "schedule" of the Sentry cron monitor for "%s" must be a cron expression, like "0 3 * * *" or "@daily", or a %s.',
                $route,
                MonitorSchedule::class
            ));
        }

        $timezone = $settings['timezone'] ?? null;
        $timezones = $timezone !== null ? DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) : [];
        if ($timezone !== null && (!is_string($timezone) || !in_array($timezone, $timezones, true))) {
            throw new InvalidConfigException(sprintf(
                'The "timezone" of the Sentry cron monitor for "%s" must be the name of a time zone, like "Europe/Berlin".',
                $route
            ));
        }

        $numbers = [];
        foreach (self::WHOLE_NUMBER_SETTINGS as $name) {
            $value = $settings[$name] ?? null;
            // E.g. from an environment variable.
            if (is_string($value) && ctype_digit($value)) {
                $value = (int)$value;
            }
            if ($value !== null && (!is_int($value) || $value < 1)) {
                throw new InvalidConfigException(sprintf(
                    'The "%s" of the Sentry cron monitor for "%s" must be a whole number of at least 1.',
                    $name,
                    $route
                ));
            }
            $numbers[$name] = $value;
        }

        return new MonitorConfig(
            $schedule,
            $numbers['checkinMargin'],
            $numbers['maxRuntime'],
            $timezone,
            $numbers['failureIssueThreshold'],
            $numbers['recoveryThreshold']
        );
    }

    private static function isCronExpression(string $schedule): bool
    {
        $schedule = trim($schedule);
        $fields = preg_split('/\s+/', $schedule);

        return in_array($schedule, self::NAMED_SCHEDULES, true) || (is_array($fields) && count($fields) === 5);
    }
}
