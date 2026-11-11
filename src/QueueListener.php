<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Sentry\Breadcrumb;
use Sentry\Event as SentryEvent;
use Sentry\EventHint;
use Sentry\ExceptionMechanism;
use Sentry\Frame;
use Sentry\Serializer\Serializer;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\PropagationContext;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Throwable;
use Yii;
use yii\base\Action;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\queue\cli\Command;
use yii\queue\cli\Queue as WorkerQueue;
use yii\queue\closure\Job as ClosureJob;
use yii\queue\ExecEvent;
use yii\queue\InvalidJobException;
use yii\queue\PushEvent;
use yii\queue\Queue;
use yii\queue\serializers\SerializerInterface;
use function Sentry\continueTrace;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;

/**
 * Reports yii2-queue jobs: a failed job becomes an error event with the job's details, a job a worker runs has a
 * scope of its own, and queuing a job is a breadcrumb. Tells the tracer when jobs are queued and run.
 *
 * @internal
 */
final class QueueListener
{
    /**
     * The settings and their defaults, see [[Component::$queue]].
     */
    public const DEFAULTS = [
        'errors' => true,
        'retries' => true,
        'jobData' => true,
        'breadcrumbs' => true,
    ];

    /**
     * The actions of yii2-queue's commands that run jobs: a worker, or the process a worker starts to run one job.
     */
    private const WORKER_ACTIONS = ['run', 'listen', 'exec'];
    /**
     * The names under which yii2-queue's functions take the job, as it is stored.
     */
    private const STORED_JOB_ARGUMENTS = ['message', 'payload', 'serialized'];
    /**
     * The most items of a list kept in the job's data, so that a job holding a long list doesn't make the event too
     * large to be accepted.
     */
    private const MAX_LIST_ITEMS = 50;
    /**
     * How deep the SDK shows nested values; deeper ones are shown as e.g. "Array of length 3".
     */
    private const MAX_DEPTH = 3;

    /**
     * @var HubInterface
     */
    private $hub;
    /**
     * @var array<string, bool>
     */
    private $settings;
    /**
     * @var bool whether a job continues the trace it carries, see [[Component::$tracing]]
     */
    private $continueTraces;
    /**
     * @var Tracer
     */
    private $tracer;
    /**
     * @var callable(string, string, callable): void registers a class-level event handler
     */
    private $listen;
    /**
     * @var callable(): ?Scrubber
     */
    private $scrubber;

    /**
     * @var list<array{0: ExecEvent, 1: bool}> the jobs running, innermost last, and whether each has a scope of its
     * own on the hub
     */
    private $running = [];
    /**
     * @var list<int> for every worker running in this process, innermost last: how many jobs were running when it
     * started
     */
    private $workers = [];

    /**
     * @param array<string, bool> $settings
     * @param callable(string, string, callable): void $listen
     * @param callable(): ?Scrubber $scrubber
     */
    public function __construct(HubInterface $hub, array $settings, bool $continueTraces, Tracer $tracer, callable $listen, callable $scrubber)
    {
        $this->hub = $hub;
        $this->settings = $settings;
        $this->continueTraces = $continueTraces;
        $this->tracer = $tracer;
        $this->listen = $listen;
        $this->scrubber = $scrubber;
    }

    public function start(): void
    {
        $this->listen(Queue::class, Queue::EVENT_BEFORE_PUSH, function (PushEvent $event): void {
            $this->beforePush($event);
        });
        $this->listen(Queue::class, Queue::EVENT_AFTER_PUSH, function (PushEvent $event): void {
            $this->afterPush($event);
        });
        $this->listen(Queue::class, Queue::EVENT_BEFORE_EXEC, function (ExecEvent $event): void {
            $this->beforeExec($event);
        });
        $this->listen(Queue::class, Queue::EVENT_AFTER_EXEC, function (ExecEvent $event): void {
            $this->afterExec($event, false);
        });
        $this->listen(Queue::class, Queue::EVENT_AFTER_ERROR, function (ExecEvent $event): void {
            $this->afterExec($event, true);
        });
        $this->listen(WorkerQueue::class, WorkerQueue::EVENT_WORKER_START, function (): void {
            // Unless a job runs the worker, e.g. to run the jobs of another queue.
            if ($this->running === []) {
                $this->tracer->stopTracingCommand();
            }
            $this->workers[] = count($this->running);
        });
        $this->listen(WorkerQueue::class, WorkerQueue::EVENT_WORKER_LOOP, function (): void {
            // Between two of the worker's jobs: one of them still open never ran, e.g. a handler marked it as handled.
            $count = count($this->workers);
            $this->endJobs($count > 0 ? $this->workers[$count - 1] : count($this->running));
        });
        $this->listen(WorkerQueue::class, WorkerQueue::EVENT_WORKER_STOP, function (): void {
            array_pop($this->workers);
        });
        $this->listen(Controller::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event): void {
            // E.g. the process a worker starts to run one job, which ends without running it when the attempt is past
            // the job's attempts.
            $app = Yii::$app;
            if ($this->running === [] && $app !== null && $event->action === $app->requestedAction && self::isWorkerAction($event->action)) {
                $this->tracer->stopTracingCommand();
            }
        });
    }

    /**
     * The class of the job running, if any.
     */
    public function currentJobName(): ?string
    {
        $count = count($this->running);

        return $count > 0 ? self::jobName($this->running[$count - 1][0]->job) : null;
    }

    /**
     * Adds the details of the job running, if any, to an event, and keeps any job, as yii2-queue stores it, out of the
     * event's stack traces.
     *
     * @internal used by [[Component]] for every event
     */
    public function processEvent(SentryEvent $sentryEvent): SentryEvent
    {
        $count = count($this->running);
        if ($count > 0) {
            return $this->addJobDetails($sentryEvent, $this->running[$count - 1][0]);
        }

        // E.g. a driver that failed to release a job once it ran.
        if ((string)$sentryEvent->getType() === 'event') {
            $scrubber = ($this->scrubber)();
            self::hideStoredJob($sentryEvent, null, $scrubber !== null ? $scrubber->replacement() : '[Filtered]');
        }

        return $sentryEvent;
    }

    private function beforePush(PushEvent $event): void
    {
        $this->tracer->startPush($event, self::jobName($event->job), self::messagingData($event->sender));

        $job = $event->job;
        if ($job instanceof TraceableJobInterface) {
            // Taken inside the span of the push, when it is traced, so that the job's transaction shows under it.
            $job->setSentryTrace([
                'sentry-trace' => getTraceparent(),
                'baggage' => getBaggage(),
                'queued_at' => microtime(true),
                'delay' => (int)$event->delay,
            ]);
        }
    }

    private function afterPush(PushEvent $event): void
    {
        $id = $event->id !== null ? (string)$event->id : null;
        $this->tracer->finishPush($event, $id !== null ? ['messaging.message.id' => $id] : []);

        if (!$this->settings['breadcrumbs']) {
            return;
        }

        $data = ['messaging.destination.name' => self::queueName($event->sender)];
        if ($id !== null) {
            $data['messaging.message.id'] = $id;
        }
        if ((int)$event->delay > 0) {
            $data['delay'] = (int)$event->delay;
        }
        $this->hub->addBreadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'queue',
            'Queued ' . self::jobName($event->job),
            $data
        ));
    }

    private function beforeExec(ExecEvent $event): void
    {
        $this->endSkippedJobs();

        // Run by a worker, unless it runs inside another job, e.g. a job running the sync queue.
        $app = Yii::$app;
        $onItsOwn = $this->running === [] && ($this->workers !== [] || self::isWorkerAction($app !== null ? $app->requestedAction : null));
        if ($onItsOwn) {
            // E.g. in the process a worker started to run this job: the job is traced, not the command.
            $this->tracer->stopTracingCommand();
        }

        // What happened so far belongs to what ran before the job.
        Yii::getLogger()->flush(true);
        $this->tracer->reportFailedHttpCalls();

        if ($onItsOwn) {
            // A fresh start: neither the breadcrumbs nor the trace of what the worker did before.
            $scope = $this->hub->pushScope();
            $scope->clearBreadcrumbs();
            $scope->setPropagationContext(PropagationContext::fromDefaults());
            // Also without tracing: the job's errors then show in the trace it carries.
            $continued = $this->continueTraceOf($event->job);
        } else {
            // Part of the request or command running it, and of its trace.
            $continued = $this->hub->getSpan() !== null ? TransactionContext::fromHeaders(getTraceparent(), getBaggage()) : null;
        }
        $this->running[] = [$event, $onItsOwn];

        $this->tracer->startJob($event, self::jobName($event->job), self::processData($event), $continued);
    }

    private function afterExec(ExecEvent $event, bool $failed): void
    {
        $index = $this->indexOf($event);
        if ($index === null) {
            if ($failed) {
                // The job ran in a process this worker started, which failed: it crashed, or ran past the job's time
                // to reserve.
                $this->reportFailureElsewhere($event);
            }

            return;
        }

        // The job's log messages are sent while its details apply.
        Yii::getLogger()->flush(true);
        if ($failed) {
            $this->reportFailure($event);
        }
        $this->endJobs($index, $failed);
    }

    private function indexOf(ExecEvent $event): ?int
    {
        foreach ($this->running as $index => [$running]) {
            if ($running === $event) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Ends the jobs running from the given position on: the ones after it never ran, e.g. a handler marked them as
     * handled.
     */
    private function endJobs(int $from, bool $failed = false): void
    {
        if (count($this->running) <= $from) {
            return;
        }

        // Calls these jobs made that got no response: not the next job's. Their own events have them already.
        $this->tracer->reportFailedHttpCalls();

        while (count($this->running) > $from) {
            [$event, $ownScope] = array_pop($this->running);
            try {
                $this->tracer->finishJob($event, $failed && count($this->running) === $from);
            } finally {
                if ($ownScope) {
                    $this->hub->popScope();
                }
            }
        }
    }

    /**
     * Ends the jobs a handler marked as handled before they ran: yii2-queue then triggers no event after them.
     */
    private function endSkippedJobs(): void
    {
        $count = count($this->running);
        while ($count > 0 && $this->running[$count - 1][0]->handled) {
            $count--;
            $this->endJobs($count);
        }
    }

    private function reportFailure(ExecEvent $event): void
    {
        $error = $event->error;
        if (!$error instanceof Throwable || !$this->reports($event)) {
            return;
        }

        $this->hub->captureEvent($this->failureEvent($event), self::failureHint($error));
    }

    private function reportFailureElsewhere(ExecEvent $event): void
    {
        $error = $event->error;
        if (!$error instanceof Throwable || !$this->reports($event)) {
            return;
        }

        $this->hub->withScope(function (Scope $scope) use ($event, $error): void {
            // Not in the trace of the worker, which runs for as long as it waits for jobs: in the job's own, if it
            // carries one.
            $scope->setPropagationContext(PropagationContext::fromDefaults());
            $this->continueTraceOf($event->job);
            $scope->addEventProcessor(function (SentryEvent $sentryEvent) use ($event): SentryEvent {
                return self::withoutProcessOutput($this->addJobDetails($sentryEvent, $event));
            });

            $sentryEvent = $this->failureEvent($event);
            // The worker's code that noticed is the same for every job: tell the jobs apart.
            $sentryEvent->setFingerprint(['{{ default }}', self::jobName($event->job)]);
            $this->hub->captureEvent($sentryEvent, self::failureHint($error));
        });
    }

    private function reports(ExecEvent $event): bool
    {
        return $this->settings['errors'] && ($event->retry !== true || $this->settings['retries']);
    }

    private function failureEvent(ExecEvent $event): SentryEvent
    {
        $sentryEvent = SentryEvent::createEvent();
        $sentryEvent->setTransaction(self::jobName($event->job));
        $sentryEvent->setTag('job.retry', $event->retry === true ? 'yes' : 'no');

        return $sentryEvent;
    }

    /**
     * Adds the job's details to an event that happens while it runs, or that reports its failure.
     */
    private function addJobDetails(SentryEvent $sentryEvent, ExecEvent $event): SentryEvent
    {
        if ((string)$sentryEvent->getType() !== 'event') {
            return $sentryEvent;
        }

        $job = $event->job;
        $details = [
            'class' => is_object($job) ? get_class($job) : null,
            'id' => $event->id !== null ? (string)$event->id : null,
            'attempt' => (int)$event->attempt,
            'queue' => self::queueName($event->sender),
            'ttr' => (int)$event->ttr,
        ];
        if ($event->retry !== null) {
            $details['retry'] = (bool)$event->retry;
        }

        $scrubber = ($this->scrubber)();
        if ($this->settings['jobData'] && is_object($job) && $scrubber !== null && $this->collectsQueueData()) {
            $data = $this->jobData($job, $scrubber);
            if ($data !== null) {
                $details['data'] = $data;
            }
        }
        $sentryEvent->setContext('job', $details);

        foreach ($sentryEvent->getExceptions() as $exception) {
            // yii2-queue's message dumps what it read, with all its data.
            if ($exception->getType() === InvalidJobException::class) {
                $exception->setValue(FailedJobText::withoutJobData($exception->getValue()));
            }
        }
        self::hideStoredJob($sentryEvent, $event, $scrubber !== null ? $scrubber->replacement() : '[Filtered]');

        return $sentryEvent;
    }

    /**
     * Whether the SDK's `data_collection` option, when it is set, lets the job's data be sent.
     */
    private function collectsQueueData(): bool
    {
        $client = $this->hub->getClient();
        $dataCollection = $client !== null ? $client->getOptions()->getDataCollection() : null;

        return $dataCollection === null || $dataCollection->shouldCollectQueues();
    }

    /**
     * The job's public properties, as the SDK shows values (objects by their class, long strings cut), with secrets
     * and personal data scrubbed.
     *
     * @return array<array-key, mixed>|null
     */
    private function jobData(object $job, Scrubber $scrubber): ?array
    {
        $client = $this->hub->getClient();
        // A closure job holds the closure's code and variables, serialized as one string.
        if ($client === null || $job instanceof ClosureJob) {
            return null;
        }

        $properties = get_object_vars($job);
        if ($job instanceof TraceableJobInterface) {
            unset($properties['sentryTrace']);
        }

        $data = (new Serializer($client->getOptions(), self::MAX_DEPTH))->serialize(self::shortened($properties));

        return is_array($data) ? $scrubber->scrub($data) : null;
    }

    /**
     * Continues the trace the job carries, if it is a [[TraceableJobInterface]].
     */
    private function continueTraceOf(?object $job): ?TransactionContext
    {
        if (!$this->continueTraces || !$job instanceof TraceableJobInterface) {
            return null;
        }

        $trace = $job->getSentryTrace() ?? [];
        $sentryTrace = $trace['sentry-trace'] ?? null;
        if (!is_string($sentryTrace) || $sentryTrace === '') {
            return null;
        }
        $baggage = $trace['baggage'] ?? null;

        return continueTrace($sentryTrace, is_string($baggage) ? $baggage : '');
    }

    private function listen(string $class, string $name, callable $handler): void
    {
        ($this->listen)($class, $name, $handler);
    }

    /**
     * @return array<string, mixed> the data of the job's span or transaction
     */
    private static function processData(ExecEvent $event): array
    {
        $data = self::messagingData($event->sender);
        if ($event->id !== null) {
            $data['messaging.message.id'] = (string)$event->id;
        }
        $data['messaging.message.retry.count'] = max(0, (int)$event->attempt - 1);

        $latency = self::receiveLatency($event);
        if ($latency !== null) {
            $data['messaging.message.receive.latency'] = $latency;
        }

        return $data;
    }

    /**
     * How long the job waited in the queue, in milliseconds (like Sentry's other SDKs): from when it was queued, or due
     * when it was delayed. Only known for a job that carries its trace.
     */
    private static function receiveLatency(ExecEvent $event): ?float
    {
        $job = $event->job;
        // A retry also waited for the attempt before it.
        if ((int)$event->attempt !== 1 || !$job instanceof TraceableJobInterface) {
            return null;
        }

        $trace = $job->getSentryTrace() ?? [];
        $queuedAt = $trace['queued_at'] ?? null;
        if (!is_float($queuedAt) && !is_int($queuedAt)) {
            return null;
        }
        $delay = is_int($trace['delay'] ?? null) ? $trace['delay'] : 0;

        return round(max(0.0, microtime(true) - $queuedAt - $delay) * 1000, 3);
    }

    /**
     * @return array<string, string>
     */
    private static function messagingData(Queue $queue): array
    {
        return [
            'messaging.system' => self::system($queue),
            'messaging.destination.name' => self::queueName($queue),
        ];
    }

    /**
     * The queue's driver, e.g. "redis" for yii\queue\redis\Queue and the classes extending it.
     */
    private static function system(Queue $queue): string
    {
        foreach (array_merge([get_class($queue)], array_values(class_parents($queue))) as $class) {
            if (preg_match('/^yii\\\\queue\\\\(\w+)\\\\Queue$/', $class, $match) === 1 && $match[1] !== 'cli') {
                return $match[1];
            }
        }

        return get_class($queue);
    }

    /**
     * The queue's application component ID, e.g. "queue".
     */
    private static function queueName(Queue $queue): string
    {
        $app = Yii::$app;
        if ($app !== null) {
            foreach ($app->getComponents(false) as $id => $component) {
                if ($component === $queue) {
                    return (string)$id;
                }
            }
        }

        return get_class($queue);
    }

    /**
     * @param mixed $job
     */
    private static function jobName($job): string
    {
        return is_object($job) ? get_class($job) : 'unknown job';
    }

    private static function isWorkerAction(?Action $action): bool
    {
        return $action !== null && $action->controller instanceof Command && in_array($action->id, self::WORKER_ACTIONS, true);
    }

    private static function failureHint(Throwable $error): EventHint
    {
        return EventHint::fromArray([
            'exception' => $error,
            // Nothing in the job caught it.
            'mechanism' => new ExceptionMechanism('auto.queue.yii2', false),
        ]);
    }

    /**
     * yii2-queue passes the job, as it is stored, from function to function: as an argument in a stack trace it would
     * show the job's data, unscrubbed. It is hidden by name in yii2-queue's own functions, and by its value wherever
     * else it is passed, e.g. to call_user_func().
     *
     * @param ExecEvent|null $event the job the event happened in, if any
     */
    private static function hideStoredJob(SentryEvent $sentryEvent, ?ExecEvent $event, ?string $replacement): void
    {
        $stacktraces = [$sentryEvent->getStacktrace()];
        foreach ($sentryEvent->getExceptions() as $exception) {
            $stacktraces[] = $exception->getStacktrace();
        }

        /** @var list<Frame> $frames */
        $frames = [];
        foreach ($stacktraces as $stacktrace) {
            if ($stacktrace === null) {
                continue;
            }
            foreach ($stacktrace->getFrames() as $frame) {
                if ($frame->getVars() !== []) {
                    $frames[] = $frame;
                }
            }
        }
        if ($frames === []) {
            return;
        }

        // The job as stored: as yii2-queue's own functions took it, shown the way the SDK shows arguments...
        $shown = [];
        foreach ($frames as $frame) {
            if (self::isQueueFrame($frame)) {
                foreach (self::STORED_JOB_ARGUMENTS as $name) {
                    $value = $frame->getVars()[$name] ?? null;
                    if (is_string($value) && $value !== '') {
                        $shown[] = $value;
                    }
                }
            }
        }
        // ...and as yii2-queue stores it, when that is known.
        $stored = $event !== null ? self::storedForms($event) : [];

        foreach ($frames as $frame) {
            $inQueue = self::isQueueFrame($frame);
            $arguments = $frame->getVars();
            foreach ($arguments as $name => $value) {
                $arguments[$name] = $inQueue && in_array($name, self::STORED_JOB_ARGUMENTS, true)
                    ? $replacement
                    : self::withoutStoredJob($value, $shown, $stored, $replacement);
            }
            $frame->setVars($arguments);
        }
    }

    private static function isQueueFrame(Frame $frame): bool
    {
        return strpos((string)$frame->getFunctionName(), 'yii\\queue\\') === 0;
    }

    /**
     * @return list<string> the job as yii2-queue stores it: what it couldn't read as a job, or the job stored again
     */
    private static function storedForms(ExecEvent $event): array
    {
        $forms = [];
        $error = $event->error;
        if ($error instanceof InvalidJobException) {
            $forms[] = (string)$error->getSerialized();
        }

        $job = $event->job;
        $serializer = $event->sender->serializer;
        if (is_object($job) && $serializer instanceof SerializerInterface) {
            try {
                $forms[] = (string)$serializer->serialize($job);
            } catch (Throwable $exception) {
                // Then only as yii2-queue's own functions took it.
            }
        }

        return array_values(array_filter($forms, static function (string $form): bool {
            return $form !== '';
        }));
    }

    /**
     * @param mixed $value an argument, as the SDK shows it: a long string is cut, and ends with " {clipped}"
     * @param list<string> $shown the stored job, as the SDK shows it
     * @param list<string> $stored the stored job
     *
     * @return mixed
     */
    private static function withoutStoredJob($value, array $shown, array $stored, ?string $replacement)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::withoutStoredJob($item, $shown, $stored, $replacement);
            }

            return $value;
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (in_array($value, $shown, true) || in_array($value, $stored, true)) {
            return $replacement;
        }

        // The start of the stored job, as much as the SDK kept of it.
        $clipped = ' {clipped}';
        if (substr($value, -strlen($clipped)) === $clipped) {
            $start = substr($value, 0, -strlen($clipped));
            foreach ($stored as $form) {
                if ($start !== '' && strpos($form, $start) === 0) {
                    return $replacement;
                }
            }
        }

        return $value;
    }

    /**
     * The worker's report that a job's process failed holds everything that process printed, which may be anything.
     */
    private static function withoutProcessOutput(SentryEvent $event): SentryEvent
    {
        foreach ($event->getExceptions() as $exception) {
            if ($exception->getType() === ProcessFailedException::class) {
                $exception->setValue(FailedJobText::withoutJobData($exception->getValue()));
            }
        }

        return $event;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed> the values, with at most MAX_LIST_ITEMS items in each list the SDK shows
     */
    private static function shortened(array $values, int $depth = 0): array
    {
        if (count($values) > self::MAX_LIST_ITEMS) {
            $values = array_slice($values, 0, self::MAX_LIST_ITEMS, true);
        }

        if ($depth + 1 < self::MAX_DEPTH) {
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    $values[$key] = self::shortened($value, $depth + 1);
                }
            }
        }

        return $values;
    }
}
