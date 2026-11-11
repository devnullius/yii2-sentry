<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionMechanism;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Severity;
use Sentry\Stacktrace;
use Sentry\StacktraceBuilder;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Throwable;
use UnexpectedValueException;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target;
use function Sentry\logger;

/**
 * Sends Yii log messages to Sentry.
 *
 * Every message at the target's [[levels]] becomes a Sentry event; the request's other messages are attached as
 * breadcrumbs. Optionally, messages are also forwarded to Sentry Logs.
 */
class LogTarget extends Target
{
    private const LEVEL_NAMES = [
        'error' => Logger::LEVEL_ERROR,
        'warning' => Logger::LEVEL_WARNING,
        'info' => Logger::LEVEL_INFO,
        'trace' => Logger::LEVEL_TRACE,
        'profile' => Logger::LEVEL_PROFILE,
    ];

    /**
     * The category yii2-queue's LogBehavior logs under.
     */
    private const QUEUE_CATEGORY = 'yii\queue\Queue';

    /**
     * The Sentry component to pass logs to, or its application component ID.
     *
     * @var Component|string
     */
    public $component = 'sentry';
    /**
     * Send one event per export, for the most severe message, with the other messages as text under the `logs`
     * additional data, like version 4 did. By default, every message becomes an event of its own.
     *
     * @var bool
     */
    public $aggregate = false;
    /**
     * Attach the log messages of a request to the events that follow them, as breadcrumbs.
     *
     * @var bool
     */
    public $breadcrumbs = true;
    /**
     * Levels of the messages that become breadcrumbs. Add 'profile' to include Yii's profiling markers.
     *
     * @var string[]
     */
    public $breadcrumbLevels = ['error', 'warning', 'info', 'trace'];
    /**
     * Replace the values in SQL log lines (strings and numbers) and in database errors with "?" in breadcrumbs and
     * Sentry Logs. Error events mask database errors with the component's `privacy` setting `maskDatabaseErrors`.
     *
     * @var bool
     */
    public $maskSqlValues = true;
    /**
     * Use the Yii log category as the event's logger name.
     *
     * @var bool
     */
    public $categoryAsLogger = true;
    /**
     * Build the event's stack trace from the trace Yii records for a message (see [[Logger::$traceLevel]]).
     *
     * @var bool
     */
    public $stackTraces = true;
    /**
     * Also forward log messages to Sentry Logs. Off by default: forwarded messages count toward your Sentry quota.
     *
     * @var bool
     */
    public $sentryLogs = false;
    /**
     * Levels of the messages forwarded to Sentry Logs.
     *
     * @var string[]
     */
    public $sentryLogLevels = ['error', 'warning', 'info'];

    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        $this->component = Instance::ensure($this->component, Component::class);
        // The package logs its own failures (e.g. while adding request data to an event); those must not be sent
        // through the component that is failing.
        $this->except[] = 'devnullius\yii2\sentry\*';

        // For Yii, no levels means every level; here that would turn every info message into a Sentry event.
        if ($this->getLevels() === 0) {
            $this->setLevels(['error', 'warning']);
        }

        // Fail on a misconfigured level now rather than while logs are being exported.
        self::levelMask($this->breadcrumbLevels);
        self::levelMask($this->sentryLogLevels);
    }

    /**
     * Same as [[Target::collect()]], but also keeps the messages needed for breadcrumbs and Sentry Logs, which may be
     * at other levels than the ones that become events.
     *
     * {@inheritdoc}
     */
    public function collect($messages, $final)
    {
        $this->messages = array_merge(
            $this->messages,
            static::filterMessages($messages, $this->collectedLevels(), $this->categories, $this->except)
        );

        $count = count($this->messages);
        if ($count > 0 && ($final || ($this->exportInterval > 0 && $count >= $this->exportInterval))) {
            // Set exportInterval to 0 to avoid triggering export again while exporting.
            $oldExportInterval = $this->exportInterval;
            $this->exportInterval = 0;
            $this->export();
            $this->exportInterval = $oldExportInterval;

            $this->messages = [];
        }
    }

    /**
     * Yii's default prefix holds the client IP, user ID and session ID. A session ID is a credential, so the default
     * prefix is only kept when the SDK may send personal data (`send_default_pii`). A custom [[prefix]] is used as is.
     *
     * {@inheritdoc}
     */
    public function getMessagePrefix($message)
    {
        if ($this->prefix !== null || $this->shouldSendDefaultPii()) {
            return parent::getMessagePrefix($message);
        }

        return '';
    }

    /**
     * @inheritDoc
     */
    public function export()
    {
        $hub = $this->component->getHub();

        if ($this->aggregate) {
            $this->exportAggregated($hub);
        } else {
            $this->exportEach($hub);
        }

        if ($this->sentryLogs) {
            $this->exportToSentryLogs();
        }
    }

    protected function getTime($timestamp): string
    {
        $parent = parent::getTime($timestamp);

        return $parent . ' ' . Yii::$app->timeZone;
    }

    protected function getSeverityLevel($yiiLogLevel): ?Severity
    {
        switch ($yiiLogLevel) {
            case Logger::LEVEL_PROFILE_END:
            case Logger::LEVEL_PROFILE_BEGIN:
            case Logger::LEVEL_PROFILE:
            case Logger::LEVEL_TRACE:
                return Severity::debug();
            case Logger::LEVEL_INFO:
                return Severity::info();
            case Logger::LEVEL_WARNING:
                return Severity::warning();
            case Logger::LEVEL_ERROR:
                return Severity::error();
            default:
                throw new UnexpectedValueException("An unsupported Yii's log level \"{$yiiLogLevel}\" given.");
        }
    }

    /**
     * @inheritDoc
     */
    protected function getContextMessage()
    {
        // Mute context
        return '';
    }

    /**
     * Every message at the target's levels becomes an event; messages at the breadcrumb levels are added as
     * breadcrumbs after it, so that the events that follow show them.
     */
    private function exportEach(HubInterface $hub): void
    {
        $eventLevels = $this->getLevels();
        $breadcrumbLevels = $this->breadcrumbs ? self::levelMask($this->breadcrumbLevels) : 0;

        foreach ($this->messages as $message) {
            $level = $message[1];
            $category = (string)$message[2];

            if (($eventLevels & $level) !== 0 && !$this->isReportedElsewhere($category, $level)) {
                $this->captureEvent($hub, $message);
            }

            if (($breadcrumbLevels & $level) !== 0 && !$this->isCoveredElsewhere($category, $level)) {
                $hub->addBreadcrumb($this->createBreadcrumb($message));
            }
        }
    }

    /**
     * Whether the component reports what an event-level message is about itself.
     */
    private function isReportedElsewhere(string $category, int $level): bool
    {
        // yii2-queue's LogBehavior logs the exception of a failed job as text; the component reports the exception.
        return $category === self::QUEUE_CATEGORY && $level === Logger::LEVEL_ERROR && $this->component->reportsQueueErrors();
    }

    /**
     * Whether the component adds breadcrumbs of its own for what the messages of this category are about.
     */
    private function isCoveredElsewhere(string $category, int $level): bool
    {
        if ($category === self::QUEUE_CATEGORY) {
            // Queuing a job is a breadcrumb, and a failed job an event of its own.
            return $level === Logger::LEVEL_ERROR ? $this->component->reportsQueueErrors() : $this->component->addsQueueBreadcrumbs();
        }

        // Every outgoing HTTP call gets a breadcrumb with its status code.
        return strpos($category, 'yii\\httpclient\\') === 0 && $this->component->tracesHttpClient();
    }

    /**
     * @param array<int, mixed> $message
     */
    private function captureEvent(HubInterface $hub, array $message): void
    {
        [$body, $level, $category, $timestamp] = $message;

        $event = Event::createEvent();
        $event->setLevel($this->getSeverityLevel($level));
        // The message may have been logged long before this export.
        $event->setTimestamp((float)$timestamp);
        $event->setTags(['category' => (string)$category]);
        if ($this->categoryAsLogger) {
            $event->setLogger((string)$category);
        }

        if ($body instanceof Throwable) {
            $hub->captureEvent($event, EventHint::fromArray([
                'exception' => $body,
                'mechanism' => new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, !$this->reachedErrorHandler($body)),
            ]));

            return;
        }

        $text = is_string($body) ? $body : VarDumper::export($body);
        if ($category === self::QUEUE_CATEGORY) {
            // LogBehavior's line about a failed job, when the component doesn't report the job itself.
            $text = FailedJobText::withoutJobData($text);
        }
        $event->setMessage($text);

        $traces = $message[4] ?? [];
        if ($this->stackTraces && is_array($traces) && $traces !== []) {
            $stacktrace = $this->buildStacktrace($hub, $traces);
            if ($stacktrace !== null) {
                $event->setStacktrace($stacktrace);
            }
        }

        $hub->captureEvent($event);
    }

    /**
     * Whether the exception reached Yii's error handler, i.e. nothing caught it and it ended the request.
     */
    private function reachedErrorHandler(Throwable $exception): bool
    {
        $app = Yii::$app;

        return $app !== null && $app->has('errorHandler', true) && $app->getErrorHandler()->exception === $exception;
    }

    /**
     * @param array<int, array<string, mixed>> $traces the frames Yii recorded, closest to the log call first
     */
    private function buildStacktrace(HubInterface $hub, array $traces): ?Stacktrace
    {
        $client = $hub->getClient();
        $first = $traces[0];
        if ($client === null || !isset($first['file'], $first['line'])) {
            return null;
        }

        $options = $client->getOptions();
        $builder = new StacktraceBuilder($options, new RepresentationSerializer($options));

        // The first frame is the call to Yii::error() and the like: its location is where the message was logged.
        return $builder->buildFromBacktrace(array_slice($traces, 1), (string)$first['file'], (int)$first['line']);
    }

    /**
     * @param array<int, mixed> $message
     */
    private function createBreadcrumb(array $message): Breadcrumb
    {
        [$body, $level, $category, $timestamp] = $message;
        $category = (string)$category;
        $data = [];

        if ($level === Logger::LEVEL_PROFILE_BEGIN) {
            $data['profile'] = 'begin';
        } elseif ($level === Logger::LEVEL_PROFILE_END) {
            $data['profile'] = 'end';
        }

        $isSql = self::isSqlCategory($category);
        if ($isSql) {
            $data['yii.category'] = $category;
        }

        return new Breadcrumb(
            self::breadcrumbLevel($level),
            $level === Logger::LEVEL_ERROR ? Breadcrumb::TYPE_ERROR : Breadcrumb::TYPE_DEFAULT,
            // The category Sentry's own integrations use for database queries.
            $isSql ? 'db.sql.query' : $category,
            $this->messageText($body, $category),
            $data,
            (float)$timestamp
        );
    }

    private function exportToSentryLogs(): void
    {
        $levels = self::levelMask($this->sentryLogLevels);
        $forwarded = false;

        foreach ($this->messages as $message) {
            [$body, $level, $category] = $message;
            if (($levels & $level) === 0) {
                continue;
            }

            $text = $this->messageText($body, (string)$category);
            $attributes = ['yii.category' => (string)$category, 'sentry.origin' => 'auto.log.yii2'];

            // No values: the message is not a format string, so "%" in it is kept as is.
            switch ($level) {
                case Logger::LEVEL_ERROR:
                    logger()->error($text, [], $attributes);
                    break;
                case Logger::LEVEL_WARNING:
                    logger()->warn($text, [], $attributes);
                    break;
                case Logger::LEVEL_INFO:
                    logger()->info($text, [], $attributes);
                    break;
                case Logger::LEVEL_TRACE:
                    logger()->debug($text, [], $attributes);
                    break;
                default:
                    logger()->trace($text, [], $attributes);
            }
            $forwarded = true;
        }

        // The SDK only sends logs when they are flushed.
        if ($forwarded) {
            logger()->flush();
        }
    }

    /**
     * The version 4 behavior: one event for the most severe message, the other messages as text.
     */
    private function exportAggregated(HubInterface $hub): void
    {
        $eventLevels = $this->getLevels();
        $messages = array_values(array_filter($this->messages, function (array $message) use ($eventLevels): bool {
            return ($eventLevels & $message[1]) !== 0 && !$this->isReportedElsewhere((string)$message[2], (int)$message[1]);
        }));
        if ($messages === []) {
            return;
        }

        // Get primary message source
        $primaryMessage = array_reduce($messages, static function ($message, $next) {
            // First iteration `$record` is null
            if (null === $message) {
                return $next;
            }

            // We need the most latest message with the most lowest level
            if ($next[1] <= $message[1]) {
                return $next;
            }

            return $message;
        });

        // Collect other logs, that appeared in request
        $logs = [];
        foreach ($messages as $message) {
            if ($message === $primaryMessage) {
                continue;
            }

            $logs[] = $this->formatMessage(self::withoutJobData($message));
        }

        // Configure scope and capture error
        $hub->withScope(function (Scope $scope) use ($hub, $primaryMessage, $logs) {
            [$body, $level, $category, $timestamp] = self::withoutJobData($primaryMessage);

            if (!empty($category)) {
                $scope->setTag('category', $category);
            }

            $scope->setExtra('logs', [] === $logs ? 'N/A' : $logs);

            if ($body instanceof Throwable) {
                $hub->captureException($body);
            } else {
                $scope->setExtra('real_body', $body);
                $scope->setExtra('real_time', $this->getTime($timestamp));

                if (isset($primaryMessage[4])) {
                    $traces = [];

                    foreach ($primaryMessage[4] as $trace) {
                        $traces[] = "in {$trace['file']}:{$trace['line']}";
                    }

                    $scope->setExtra('traces', $traces);
                } else {
                    $scope->setExtra('traces', 'N/A');
                }

                if (is_string($body)) {
                    $text = $body;
                } else {
                    $text = VarDumper::export($body);
                }

                $hub->captureMessage($text, $this->getSeverityLevel($level));
            }
        });
    }

    /**
     * @param array<int, mixed> $message
     *
     * @return array<int, mixed> the message; LogBehavior's line about a failed job without the job's data it may hold
     */
    private static function withoutJobData(array $message): array
    {
        if (($message[2] ?? null) === self::QUEUE_CATEGORY && is_string($message[0] ?? null)) {
            $message[0] = FailedJobText::withoutJobData($message[0]);
        }

        return $message;
    }

    /**
     * @param mixed $body
     */
    private function messageText($body, string $category): string
    {
        if ($body instanceof Throwable) {
            $message = $body->getMessage();
            if ($this->maskSqlValues && SqlMasker::isDatabaseError(get_class($body))) {
                $message = SqlMasker::maskErrorMessage($message);
            }

            return get_class($body) . ': ' . $message;
        }

        $text = is_string($body) ? $body : VarDumper::export($body);
        if ($this->maskSqlValues && self::isSqlCategory($category)) {
            $text = SqlMasker::mask($text);
        }

        // Lines Yii itself writes, e.g. on logins, may carry secrets or personal data.
        return $this->component->scrubLogMessage($category, $text);
    }

    /**
     * The levels this target keeps: the event levels, plus the ones needed for breadcrumbs and Sentry Logs.
     */
    private function collectedLevels(): int
    {
        $levels = $this->getLevels();

        if ($this->breadcrumbs && !$this->aggregate) {
            $levels |= self::levelMask($this->breadcrumbLevels);
        }
        if ($this->sentryLogs) {
            $levels |= self::levelMask($this->sentryLogLevels);
        }

        return $levels;
    }

    private function shouldSendDefaultPii(): bool
    {
        $client = $this->component->getHub()->getClient();

        return $client !== null && $client->getOptions()->shouldSendDefaultPii();
    }

    /**
     * @param string[] $names
     *
     * @throws InvalidConfigException on an unknown level name
     */
    private static function levelMask(array $names): int
    {
        $mask = 0;
        foreach ($names as $name) {
            if (!isset(self::LEVEL_NAMES[$name])) {
                throw new InvalidConfigException(sprintf(
                    'Unknown log level "%s"; use: %s.',
                    $name,
                    implode(', ', array_keys(self::LEVEL_NAMES))
                ));
            }
            $mask |= self::LEVEL_NAMES[$name];
        }

        return $mask;
    }

    private static function breadcrumbLevel(int $level): string
    {
        switch ($level) {
            case Logger::LEVEL_ERROR:
                return Breadcrumb::LEVEL_ERROR;
            case Logger::LEVEL_WARNING:
                return Breadcrumb::LEVEL_WARNING;
            case Logger::LEVEL_INFO:
                return Breadcrumb::LEVEL_INFO;
            default:
                return Breadcrumb::LEVEL_DEBUG;
        }
    }

    private static function isSqlCategory(string $category): bool
    {
        // yii\db\Command logs each query under its method name, e.g. "yii\db\Command::query".
        return strpos($category, 'yii\db\Command::') === 0;
    }
}
