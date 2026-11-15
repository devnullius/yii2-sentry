<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Sentry\Breadcrumb;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Throwable;
use Yii;
use yii\base\Action;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Controller;
use yii\base\Event;
use yii\base\View;
use yii\base\ViewEvent;
use yii\console\Application as ConsoleApplication;
use yii\httpclient\Client;
use yii\httpclient\Request as HttpClientRequest;
use yii\httpclient\RequestEvent;
use yii\httpclient\Response as HttpClientResponse;
use yii\log\Logger;
use yii\log\Target;
use yii\queue\Queue;
use yii\web\Application as WebApplication;
use yii\web\Response;
use function Sentry\continueTrace;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;

/**
 * Traces one web request or console command: a transaction, with spans for bootstrap, actions, views, outgoing HTTP
 * calls, SQL queries and queue jobs. A queue job run by a worker is a transaction of its own. Also continues the trace
 * of the caller, and passes the trace on to the services called.
 *
 * @internal
 */
final class Tracer
{
    /**
     * The settings and their defaults, see [[Component::$tracing]].
     */
    public const DEFAULTS = [
        'requests' => true,
        'commands' => true,
        'actions' => true,
        'views' => true,
        'httpClient' => true,
        'database' => true,
        'queue' => true,
        'continueTraces' => true,
        'maskSqlValues' => true,
    ];

    /**
     * The most SQL profiling markers kept for a transaction; the SDK records at most 1000 spans per transaction.
     */
    private const MAX_PROFILING_MARKERS = 2000;

    /**
     * @var HubInterface
     */
    private $hub;
    /**
     * @var array<string, bool>
     */
    private $settings;
    /**
     * @var callable(string, string, callable): void registers a class-level event handler
     */
    private $listen;
    /**
     * @var callable(): ?Scrubber
     */
    private $scrubber;
    /**
     * @var string the directory paths of views are shown relative to
     */
    private $root;

    /**
     * @var Transaction|null
     */
    private $transaction;
    /**
     * @var int|false|null the process the transaction started in: a process it forks doesn't finish it
     */
    private $pid;
    /**
     * @var object|null the queue job the transaction traces, when it is the job's own
     */
    private $job;
    /**
     * @var Span|null the hub's span when the job's own transaction started, given back when it finishes
     */
    private $spanBeforeJob;
    /**
     * @var bool whether the transaction has its final name
     */
    private $named = false;
    /**
     * @var bool
     */
    private $finished = false;
    /**
     * @var bool whether the error handler took over, so that what was running has failed
     */
    private $failedWorkClosed = false;
    /**
     * @var Span|null
     */
    private $bootstrapSpan;
    /**
     * @var list<array{0: object|string, 1: Span}> the open action and view spans, with what they trace, innermost last
     */
    private $open = [];
    /**
     * @var list<Span> every action and view span, to place SQL query spans in
     */
    private $workSpans = [];
    /**
     * @var array<int, array{0: HttpClientRequest, 1: Span|null, 2: float}> the outgoing HTTP calls waiting for their
     * response, with their span and start time, by object ID; holding the request keeps its ID from being reused
     */
    private $httpCalls = [];
    /**
     * @var list<array<int, mixed>> SQL profiling markers handed over by the log
     */
    private $profilingMarkers = [];
    /**
     * @var bool
     */
    private $shutdownRegistered = false;

    /**
     * @param array<string, bool> $settings
     * @param callable(string, string, callable): void $listen
     * @param callable(): ?Scrubber $scrubber
     */
    public function __construct(HubInterface $hub, array $settings, callable $listen, callable $scrubber, string $root)
    {
        $this->hub = $hub;
        $this->settings = $settings;
        $this->listen = $listen;
        $this->scrubber = $scrubber;
        $this->root = rtrim($root, '/\\');
    }

    /**
     * Starts tracing the application's request or command; called while the application bootstraps.
     */
    public function start(Application $app): void
    {
        $context = $this->settings['continueTraces'] ? self::continueIncomingTrace($app) : null;

        if ($this->settings['httpClient'] && class_exists(Client::class)) {
            $this->listen(Client::class, Client::EVENT_BEFORE_SEND, function (RequestEvent $event): void {
                $this->beforeHttpCall($event->request);
            });
            $this->listen(Client::class, Client::EVENT_AFTER_SEND, function (RequestEvent $event): void {
                $this->afterHttpCall($event->request, $event->response);
            });
        }

        if (!$this->tracingEnabled()) {
            return;
        }

        if ($app instanceof WebApplication && $this->settings['requests']) {
            $context = ($context ?? TransactionContext::make())
                ->setOp('http.server')
                ->setOrigin('auto.http.yii2')
                ->setData(['http.request.method' => $app->getRequest()->getMethod()]);
            $context->setName(self::requestPath($app));
            $context->setSource(TransactionSource::url());
            $this->startTransaction($context);

            // After the response has been sent, and after the component let the visitor go (sendAfterResponse).
            $this->listen(Response::class, Response::EVENT_AFTER_SEND, function (Event $event): void {
                $response = $event->sender;
                $this->finish($response instanceof Response ? $response->getStatusCode() : null);
            });
        } elseif ($app instanceof ConsoleApplication && $this->settings['commands']) {
            $context = ($context ?? TransactionContext::make())
                ->setOp('console.command')
                ->setOrigin('auto.console.yii2');
            $context->setName(self::commandRoute($app));
            $context->setSource(TransactionSource::task());
            $this->startTransaction($context);

            $result = new CommandResult($app, $this->listen);
            $this->listen(ConsoleApplication::class, Application::EVENT_AFTER_REQUEST, function () use ($result): void {
                $this->finish(null, $result->failed());
            });
        }

        // Queue jobs can be traced even when the request or command isn't, e.g. in a worker, which is not traced as
        // a command: the spans of their actions, views and queries are then needed too.
        $tracesJobs = $this->settings['queue'] && class_exists(Queue::class);
        if ($this->transaction === null && !$tracesJobs) {
            return;
        }

        $this->listen(Controller::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event) use ($app): void {
            $this->beforeAction($event->action, $app);
        });

        $transaction = $this->transaction;
        if ($transaction !== null && $this->traces('spans')) {
            $this->bootstrapSpan = $transaction->startChild(
                SpanContext::make()
                    ->setOp('app.bootstrap')
                    ->setOrigin('auto.yii2')
                    ->setStartTimestamp($transaction->getStartTimestamp())
            );
            $this->listen(Application::class, Application::EVENT_BEFORE_REQUEST, function (): void {
                $this->finishBootstrap();
            });
        } elseif (!$tracesJobs) {
            return;
        }

        if ($this->settings['actions']) {
            $this->listen(Controller::class, Controller::EVENT_AFTER_ACTION, function (ActionEvent $event): void {
                $this->closeSpan($event->action, SpanStatus::ok());
            });
        }

        if ($this->settings['views']) {
            $this->listen(View::class, View::EVENT_BEFORE_RENDER, function (ViewEvent $event): void {
                $this->openSpan('view:' . $event->viewFile, 'view.render', $this->relativePath((string)$event->viewFile), 'auto.view.yii2');
            });
            $this->listen(View::class, View::EVENT_AFTER_RENDER, function (ViewEvent $event): void {
                $this->closeSpan('view:' . $event->viewFile, SpanStatus::ok());
            });
        }

        if ($this->settings['database']) {
            $app->getLog()->targets['sentry-tracing'] = new ProfilingTarget(function (array $messages): void {
                $this->receiveProfilingMarkers($messages);
            });
        }
    }

    /**
     * Finishes the transaction and sends it to Sentry, unless it has been finished already.
     *
     * @param int|null $httpStatus the status code of the response, for a web request
     * @param bool $failed whether the request or command failed
     */
    public function finish(?int $httpStatus = null, bool $failed = false): void
    {
        $transaction = $this->transaction;
        // A process the request or command forked, e.g. to do part of its work, doesn't finish it.
        if ($transaction === null || $this->finished || $this->pid !== getmypid()) {
            return;
        }
        $this->finished = true;
        $failed = $failed || self::isHandlingAnException(Yii::$app);
        $this->reportFailedHttpCalls();

        try {
            $this->finishBootstrap();
            $this->closeOpenSpans($failed ? SpanStatus::internalError() : null);
            if ($this->traces('database')) {
                $this->addQuerySpans();
            }

            if ($httpStatus !== null) {
                // Also adds the response's status code to the events that follow.
                $transaction->setHttpStatus($httpStatus);
            }
            if ($failed && ($httpStatus === null || $httpStatus < 400)) {
                $transaction->setStatus(SpanStatus::internalError());
            } elseif ($httpStatus === null) {
                $transaction->setStatus(SpanStatus::ok());
            }
        } finally {
            $transaction->finish();
            // Errors reported later, e.g. when the log is flushed at the end, belong to the same trace.
            $this->hub->setSpan($transaction);
        }
    }

    /**
     * Finishes the transaction when PHP stops before the request or command ended normally, e.g. after an uncaught
     * exception in a console command, or when the next application is bootstrapped in the same process.
     */
    public function end(): void
    {
        $transaction = $this->transaction;
        if ($this->job !== null || ($transaction !== null && $transaction->getOp() === 'console.command')) {
            // The job or command never returned: it crashed, or ended its process itself, e.g. with exit(), so that
            // its exit code can't be known.
            $this->finish(null, true);

            return;
        }

        $app = Yii::$app;
        $error = error_get_last();
        $fatal = $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
        $response = $app !== null && $app->has('response', true) ? $app->getResponse() : null;

        $this->finish($response instanceof Response ? $response->getStatusCode() : null, $fatal);
    }

    private function startTransaction(TransactionContext $context): void
    {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? (defined('YII_BEGIN_TIME') ? YII_BEGIN_TIME : null);
        $context->setStartTimestamp(is_numeric($start) ? (float)$start : microtime(true));

        $this->transaction = $this->hub->startTransaction($context);
        $this->pid = getmypid();
        $this->hub->setSpan($this->transaction);
        $this->registerShutdown();
    }

    /**
     * Stops tracing the console command: a queue worker runs for as long as it waits for jobs, so its jobs are traced
     * one by one instead. What was recorded for the command is dropped.
     */
    public function stopTracingCommand(): void
    {
        $transaction = $this->transaction;
        if ($transaction === null || $this->job !== null || $transaction->getOp() !== 'console.command') {
            return;
        }

        $this->reset();
        $this->hub->setSpan(null);

        // Finished without being sent: until it is finished, the SDK's profilers keep running for it, for as long as
        // the worker runs.
        $transaction->setSampled(false);
        $transaction->finish();
    }

    /**
     * Starts tracing a queue job: as a span of the request or command it runs in, e.g. with the sync queue, or as a
     * transaction of its own.
     *
     * @param object $job what the job's tracing is finished with
     * @param array<string, mixed> $data
     * @param TransactionContext|null $continued the trace of the code that queued the job, if the job carries it
     */
    public function startJob(object $job, string $name, array $data, ?TransactionContext $continued): void
    {
        if (!$this->settings['queue'] || !$this->tracingEnabled()) {
            return;
        }

        if ($this->transaction !== null && !$this->finished) {
            $this->openSpan($job, 'queue.process', $name, 'auto.queue.yii2', $data);

            return;
        }

        $context = ($continued ?? TransactionContext::make())
            ->setOp('queue.process')
            ->setOrigin('auto.queue.yii2')
            ->setData($data)
            ->setStartTimestamp(microtime(true));
        $context->setName($name);
        $context->setSource(TransactionSource::task());

        $spanBefore = $this->hub->getSpan();
        $this->reset();
        $this->job = $job;
        $this->spanBeforeJob = $spanBefore;
        $this->named = true;
        $this->transaction = $this->hub->startTransaction($context);
        $this->pid = getmypid();
        $this->hub->setSpan($this->transaction);
        $this->registerShutdown();
    }

    /**
     * @param object $job as passed to [[startJob()]]
     */
    public function finishJob(object $job, bool $failed): void
    {
        if ($this->job !== $job) {
            $this->closeSpan($job, $failed ? SpanStatus::internalError() : SpanStatus::ok());

            return;
        }

        $spanBefore = $this->spanBeforeJob;
        try {
            $this->finish(null, $failed);
        } finally {
            $this->reset();
            // E.g. the request running the job: what follows belongs to it again.
            $this->hub->setSpan($spanBefore);
        }
    }

    /**
     * Opens the span of a queue job being queued.
     *
     * @param object $push what the span is closed with
     * @param array<string, mixed> $data
     */
    public function startPush(object $push, string $name, array $data): void
    {
        if ($this->settings['queue']) {
            $this->openSpan($push, 'queue.publish', $name, 'auto.queue.yii2', $data);
        }
    }

    /**
     * @param object $push as passed to [[startPush()]]
     * @param array<string, mixed> $data
     */
    public function finishPush(object $push, array $data): void
    {
        $this->closeSpan($push, SpanStatus::ok(), $data);
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
                Yii::warning('Unable to finish the Sentry transaction: ' . $exception, self::class);
            }
        });
    }

    /**
     * Forgets the transaction and what was recorded for it.
     */
    private function reset(): void
    {
        $this->transaction = null;
        $this->pid = null;
        $this->job = null;
        $this->spanBeforeJob = null;
        $this->named = false;
        $this->finished = false;
        $this->failedWorkClosed = false;
        $this->bootstrapSpan = null;
        $this->open = [];
        $this->workSpans = [];
        $this->profilingMarkers = [];
    }

    /**
     * Names the transaction after the requested route, and keeps the spans of failed work from holding the error page.
     */
    private function beforeAction(Action $action, Application $app): void
    {
        $handlingAnException = self::isHandlingAnException($app);

        if (!$this->named && $this->transaction !== null && $action === $app->requestedAction && !$handlingAnException) {
            $this->transaction->setName($action->getUniqueId());
            if ($app instanceof WebApplication) {
                $this->transaction->getMetadata()->setSource(TransactionSource::route());
            }
            $this->named = true;
        }

        if (!$this->traces('actions')) {
            return;
        }

        if ($handlingAnException && !$this->failedWorkClosed) {
            // The error handler runs the error page: whatever was running has failed.
            $this->closeOpenSpans(SpanStatus::internalError());
            $this->failedWorkClosed = true;
        }

        $this->openSpan($action, 'yii.action', $action->getUniqueId(), 'auto.function.yii2');
    }

    /**
     * @param object|string $subject what the span traces, to close it with
     * @param array<string, mixed> $data
     */
    private function openSpan($subject, string $op, string $description, string $origin, array $data = []): void
    {
        // Only while a transaction that is sent is running.
        if ($this->finished || !$this->traces('spans')) {
            return;
        }
        $parent = $this->hub->getSpan() ?? $this->transaction;
        if ($parent === null) {
            return;
        }

        $context = SpanContext::make()->setOp($op)->setDescription($description)->setOrigin($origin);
        if ($data !== []) {
            $context->setData($data);
        }
        $span = $parent->startChild($context);
        $this->open[] = [$subject, $span];
        $this->workSpans[] = $span;
        $this->hub->setSpan($span);
    }

    /**
     * @param object|string $subject
     * @param array<string, mixed> $data added to the span's data
     */
    private function closeSpan($subject, SpanStatus $status, array $data = []): void
    {
        for ($index = count($this->open) - 1; $index >= 0; $index--) {
            if ($this->open[$index][0] !== $subject) {
                continue;
            }

            // Spans opened inside it and still open were left by an exception that was caught.
            while (count($this->open) > $index + 1) {
                $abandoned = array_pop($this->open)[1];
                $abandoned->setStatus(SpanStatus::internalError());
                $abandoned->finish();
            }

            $span = array_pop($this->open)[1];
            $span->setStatus($status);
            if ($data !== []) {
                $span->setData(array_merge($span->getData(), $data));
            }
            $span->finish();
            $this->hub->setSpan($this->open !== [] ? $this->open[count($this->open) - 1][1] : $this->transaction);

            return;
        }
    }

    private function closeOpenSpans(?SpanStatus $status): void
    {
        while ($this->open !== []) {
            $span = array_pop($this->open)[1];
            if ($status !== null) {
                $span->setStatus($status);
            }
            $span->finish();
        }

        if ($this->transaction !== null) {
            $this->hub->setSpan($this->transaction);
        }
    }

    private function finishBootstrap(): void
    {
        if ($this->bootstrapSpan !== null && $this->bootstrapSpan->getEndTimestamp() === null) {
            $this->bootstrapSpan->finish();
        }
    }

    private function beforeHttpCall(HttpClientRequest $request): void
    {
        $method = strtoupper((string)$request->getMethod());
        [$url] = self::splitUrl((string)$request->getFullUrl());

        $span = null;
        $parent = $this->hub->getSpan();
        if ($parent !== null && $parent->getSampled()) {
            $recordedUrl = self::scrubCredentials($url, ($this->scrubber)());
            $span = $parent->startChild(
                SpanContext::make()
                    ->setOp('http.client')
                    ->setDescription("$method $recordedUrl")
                    ->setOrigin('auto.http.yii2_httpclient')
                    ->setData(['http.request.method' => $method, 'url' => $recordedUrl])
            );
        }
        $this->httpCalls[spl_object_id($request)] = [$request, $span, microtime(true)];

        $headers = $request->getHeaders();
        if (!$headers->has('sentry-trace') && $this->propagatesTraceTo($url)) {
            // Under the span of the call when it is traced, so that the called service's work shows inside it.
            $headers->set('sentry-trace', $span !== null ? $span->toTraceparent() : getTraceparent());
            $baggage = $span !== null ? $span->toBaggage() : getBaggage();
            if ($baggage !== '' && !$headers->has('baggage')) {
                $headers->set('baggage', $baggage);
            }
        }
    }

    private function afterHttpCall(HttpClientRequest $request, ?HttpClientResponse $response): void
    {
        $call = $this->httpCalls[spl_object_id($request)] ?? null;
        unset($this->httpCalls[spl_object_id($request)]);
        $span = $call !== null && $call[0] === $request ? $call[1] : null;

        $statusCode = $response !== null ? (int)$response->getStatusCode() : 0;
        $data = $this->httpCallData($request);
        $data['http.response.status_code'] = $statusCode;

        if ($span !== null) {
            $span->setStatus(SpanStatus::createFromHttpStatusCode($statusCode));
            $span->setData(array_merge($span->getData(), $data));
            $span->finish();
        }

        if ($statusCode >= 500) {
            $level = Breadcrumb::LEVEL_ERROR;
        } elseif ($statusCode >= 400) {
            $level = Breadcrumb::LEVEL_WARNING;
        } else {
            $level = Breadcrumb::LEVEL_INFO;
        }
        $this->hub->addBreadcrumb(new Breadcrumb($level, Breadcrumb::TYPE_HTTP, 'http', null, $data));
    }

    /**
     * Adds a breadcrumb for every outgoing HTTP call that never got its response, e.g. because the connection failed,
     * and closes its span as failed. The transport threw an exception instead of reporting back, which is why this
     * happens as soon as the next event is reported.
     *
     * @return list<Breadcrumb> the breadcrumbs added
     */
    public function reportFailedHttpCalls(): array
    {
        $breadcrumbs = [];

        foreach ($this->httpCalls as [$request, $span, $start]) {
            if ($span !== null) {
                $span->setStatus(SpanStatus::internalError());
                $span->finish();
            }

            $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_ERROR, Breadcrumb::TYPE_HTTP, 'http', 'No response', $this->httpCallData($request), $start);
            $this->hub->addBreadcrumb($breadcrumb);
            $breadcrumbs[] = $breadcrumb;
        }
        $this->httpCalls = [];

        return $breadcrumbs;
    }

    /**
     * @return array<string, string> the call's URL without the query string, with its credentials scrubbed, its method,
     *                               and its scrubbed query string
     */
    private function httpCallData(HttpClientRequest $request): array
    {
        [$url, $query] = self::splitUrl((string)$request->getFullUrl());
        $scrubber = ($this->scrubber)();
        $data = [
            'url' => self::scrubCredentials($url, $scrubber),
            'http.request.method' => strtoupper((string)$request->getMethod()),
        ];

        if ($query !== '' && $scrubber !== null) {
            $data['http.query'] = $scrubber->scrubQueryString($query);
        }

        return $data;
    }

    private function propagatesTraceTo(string $url): bool
    {
        $client = $this->hub->getClient();
        if ($client === null) {
            return false;
        }

        // Like the SDK's own integrations: every host, unless trace_propagation_targets lists the hosts.
        $targets = $client->getOptions()->getTracePropagationTargets();

        return $targets === null || in_array(parse_url($url, PHP_URL_HOST), $targets, true);
    }

    /**
     * @param list<array<int, mixed>> $messages
     */
    private function receiveProfilingMarkers(array $messages): void
    {
        if ($this->finished || !$this->traces('database')) {
            return;
        }

        foreach ($messages as $message) {
            if (count($this->profilingMarkers) >= self::MAX_PROFILING_MARKERS) {
                return;
            }
            $this->profilingMarkers[] = $message;
        }
    }

    /**
     * Turns the SQL profiling markers Yii logged into spans, placed in the action or view that ran each query.
     */
    private function addQuerySpans(): void
    {
        $transaction = $this->transaction;
        if ($transaction === null) {
            return;
        }

        // The markers logged since the log was last flushed have not reached any log target yet.
        $markers = array_merge(
            $this->profilingMarkers,
            Target::filterMessages(Yii::getLogger()->messages, Logger::LEVEL_PROFILE, ProfilingTarget::CATEGORIES)
        );

        $started = [];
        foreach ($markers as $marker) {
            [$token, $level, $category, $timestamp] = $marker;
            $key = $category . "\0" . $token;

            if ($level === Logger::LEVEL_PROFILE_BEGIN) {
                $started[$key][] = (float)$timestamp;
            } elseif ($level === Logger::LEVEL_PROFILE_END && !empty($started[$key])) {
                $start = array_pop($started[$key]);
                // Not a query from before the transaction, e.g. of the job a worker ran before.
                if ($start >= $transaction->getStartTimestamp()) {
                    $this->addQuerySpan((string)$token, $start, (float)$timestamp);
                }
            }
        }
    }

    private function addQuerySpan(string $sql, float $start, float $end): void
    {
        $parent = $this->transaction;
        if ($parent === null) {
            return;
        }

        // The innermost action or view that ran while the query did.
        $parentStart = -INF;
        foreach ($this->workSpans as $span) {
            $spanEnd = $span->getEndTimestamp() ?? INF;
            if ($span->getStartTimestamp() <= $start && $end <= $spanEnd && $span->getStartTimestamp() >= $parentStart) {
                $parent = $span;
                $parentStart = $span->getStartTimestamp();
            }
        }

        $parent->startChild(
            SpanContext::make()
                ->setOp('db.sql.query')
                ->setDescription($this->settings['maskSqlValues'] ? SqlMasker::mask($sql) : $sql)
                ->setOrigin('auto.db.yii2')
                ->setStartTimestamp($start)
        )->finish($end);
    }

    /**
     * @param string $what "spans", or the setting of a kind of span
     */
    private function traces(string $what): bool
    {
        $sampled = $this->transaction !== null && $this->transaction->getSampled() === true;

        return $what === 'spans' ? $sampled : $sampled && $this->settings[$what];
    }

    private function relativePath(string $path): string
    {
        $prefix = $this->root . DIRECTORY_SEPARATOR;

        return strpos($path, $prefix) === 0 ? substr($path, strlen($prefix)) : $path;
    }

    private function listen(string $class, string $name, callable $handler): void
    {
        ($this->listen)($class, $name, $handler);
    }

    private function tracingEnabled(): bool
    {
        $client = $this->hub->getClient();

        return $client !== null && $client->getOptions()->isTracingEnabled();
    }

    private static function continueIncomingTrace(Application $app): ?TransactionContext
    {
        if (!$app instanceof WebApplication) {
            return null;
        }

        $headers = $app->getRequest()->getHeaders();
        $sentryTrace = (string)$headers->get('sentry-trace', '');
        if ($sentryTrace === '') {
            return null;
        }

        return continueTrace($sentryTrace, (string)$headers->get('baggage', ''));
    }

    private static function requestPath(WebApplication $app): string
    {
        try {
            return '/' . ltrim($app->getRequest()->getPathInfo(), '/');
        } catch (Throwable $exception) {
            // E.g. a server setup where Yii can't tell the path.
            return '/';
        }
    }

    private static function commandRoute(ConsoleApplication $app): string
    {
        // The route is the first argument; the ones after it may hold secrets.
        $route = (string)($_SERVER['argv'][1] ?? '');

        return $route === '' || $route[0] === '-' ? $app->defaultRoute : $route;
    }

    /**
     * @return array{0: string, 1: string} the URL without its query string and fragment, and the query string
     */
    private static function splitUrl(string $url): array
    {
        $fragment = strpos($url, '#');
        if ($fragment !== false) {
            $url = substr($url, 0, $fragment);
        }

        $query = strpos($url, '?');

        return $query === false ? [$url, ''] : [substr($url, 0, $query), substr($url, $query + 1)];
    }

    /**
     * A URL as it is recorded: its credentials, e.g. "https://user:password@...", scrubbed like other secrets, or left
     * out when there is no scrubber, like the query string.
     */
    private static function scrubCredentials(string $url, ?Scrubber $scrubber): string
    {
        return $scrubber !== null ? $scrubber->scrubCredentials($url) : Scrubber::replaceCredentials($url, '');
    }

    private static function isHandlingAnException(?Application $app): bool
    {
        return $app !== null && $app->has('errorHandler', true) && $app->getErrorHandler()->exception !== null;
    }
}
