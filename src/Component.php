<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Closure;
use ReflectionClass;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\KeyValueCollectionBehavior;
use Sentry\Event as SentryEvent;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\Integration\ModulesIntegration;
use Sentry\Integration\RequestIntegration;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Throwable;
use Yii;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\Controller;
use yii\base\Event;
use yii\base\InlineAction;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApplication;
use yii\helpers\Html;
use yii\httpclient\Client;
use yii\queue\Queue;
use yii\web\Response;
use yii\web\User;
use function Sentry\getBaggage;
use function Sentry\getTraceparent;

class Component extends \yii\base\Component implements BootstrapInterface
{
    /**
     * @var string|false|null Sentry DSN. When it is not set, the SDK reads the `SENTRY_DSN` environment variable.
     * Set it to `false` to disable sending events.
     */
    public $dsn;
    /**
     * @var array Sentry SDK options, passed as is. They take precedence over the component's defaults.
     */
    public $sentrySettings = [];
    /**
     * @var array Sentry integrations: class names, Yii configuration arrays or instances.
     *
     * The SDK's error and fatal-error listeners are not included: Yii's error handler and [[LogTarget]] already
     * report PHP errors, and both together would report each one twice.
     */
    public $integrations = [
        Integration::class,
        FrameContextifierIntegration::class,
        EnvironmentIntegration::class,
        ModulesIntegration::class,
        RequestIntegration::class,
    ];
    /**
     * @var array<string, mixed> Settings for this package's [[Integration]], which decides what Sentry receives
     * about requests, function arguments and database errors: which secrets and personal data are scrubbed, and
     * which request bodies are sent. For example:
     *
     * ```php
     * 'privacy' => [
     *     // Show personal data, like email addresses, even without send_default_pii.
     *     'scrubPersonalData' => false,
     *     'secretFields' => array_merge(Integration::SECRET_FIELDS, ['license']),
     * ],
     * ```
     *
     * See the [[Integration]] properties for all settings.
     */
    public $privacy = [];
    /**
     * @var array<string, bool> What is traced, once tracing is on with the SDK's `traces_sample_rate` or
     * `traces_sampler` option. All parts are on by default:
     *
     * - `requests`: a transaction for every web request, named by its route, with its HTTP status, timed from when
     *   PHP received the request.
     * - `commands`: a transaction for every console command, except yii2-queue workers (`queue/listen`,
     *   `queue/run`): they run for as long as they wait for jobs, so their jobs are traced one by one instead. It is
     *   marked as failed when the command's exit code isn't 0, or can't be known: when it ends with `exit()`,
     *   `Yii::$app->end()` or an `ExitException` instead of returning the exit code from its action.
     * - `actions`: a span for every controller action.
     * - `views`: a span for every view rendered.
     * - `httpClient`: a span and a breadcrumb for every call made with yii2-httpclient, and the trace headers the
     *   called service needs to continue the trace (to the hosts in the SDK's `trace_propagation_targets`, or all).
     *   Breadcrumbs and trace headers are added without tracing too.
     * - `database`: a span for every SQL query, with values masked, from Yii's DB profiling (`enableProfiling`).
     * - `queue`: a transaction for every yii2-queue job a worker runs, named by the job's class (a span when the job
     *   runs inside a traced request or command, as with the sync queue), and a span for every job queued.
     * - `continueTraces`: continue the trace of the caller (the `sentry-trace` and `baggage` headers), so that this
     *   request's errors and spans show in it; also without tracing. The same for queue jobs that carry the trace of
     *   the code that queued them, see [[TraceableJobInterface]].
     */
    public $tracing = [];
    /**
     * @var array<string, bool> What is reported about yii2-queue jobs, when yii2-queue is installed. All parts are on
     * by default:
     *
     * - `errors`: a job that fails becomes an error event: its exception, with the job's class, ID, attempt and queue,
     *   and whether yii2-queue will retry it (the `job.retry` tag). The line yii2-queue's `LogBehavior` logs about
     *   the same failure doesn't become a second event. A worker also reports a job that ended the process running
     *   it, or that ran past its time to reserve (without what that process printed).
     * - `retries`: also report a failed attempt that yii2-queue will retry. Turn it off to report a job only once it
     *   has failed for good.
     * - `jobData`: add the job's public properties to its events, scrubbed like request bodies (see [[privacy]]):
     *   secrets are always hidden, personal data while the SDK's `send_default_pii` is off (or, when the SDK's
     *   `data_collection` option is set, while that doesn't collect `user_info`). Never when `data_collection` is set
     *   with `queues` off.
     * - `breadcrumbs`: a breadcrumb for every job queued, in place of the lines `LogBehavior` logs.
     *
     * A job a worker runs also has a scope of its own: its events don't carry what the job before it did. The log
     * messages a job writes are sent when it ends, with its details. A job, as yii2-queue stores it, is hidden from the
     * arguments in stack traces: where yii2-queue's own functions take it, and where it is passed on from there. What
     * LogBehavior logs about a failed job is sent without the job's data it may hold: what the job's process printed,
     * or a dump of a job that couldn't be read.
     */
    public $queue = [];
    /**
     * @var array<int|string, mixed> Console commands to monitor with Sentry Crons, by route, as in the crontab line
     * that runs them. Sentry is told when each run starts and how it ends: succeeded (exit code 0) or failed (another
     * exit code, or a crash), so that it alerts when a run fails, takes too long or doesn't happen at all. The errors
     * a run reports show with it. Nothing is monitored by default. For example:
     *
     * ```php
     * 'cronMonitors' => [
     *     // Reports to the monitor "report-daily", set up in Sentry.
     *     'report/daily',
     *     // Reports to the monitor "nightly-cleanup", set up in Sentry.
     *     'cleanup/run' => 'nightly-cleanup',
     *     // Creates or updates the monitor in Sentry, at every run.
     *     'billing/charge' => [
     *         'slug' => 'billing',            // the monitor's name; by default from the route, "billing-charge"
     *         'schedule' => '0 3 * * *',      // as in the crontab line: keep the two the same
     *         'timezone' => 'Europe/Berlin',  // of the schedule; Sentry assumes UTC
     *         'checkinMargin' => 5,           // minutes a run may start late before it counts as missed
     *         'maxRuntime' => 30,             // minutes a run may take before it counts as timed out
     *         'failureIssueThreshold' => 2,   // failed runs in a row before Sentry opens an issue
     *         'recoveryThreshold' => 1,       // successful runs in a row before Sentry resolves it
     *     ],
     * ],
     * ```
     *
     * Sentry's defaults apply to the settings left out. `schedule` can also be a `Sentry\MonitorSchedule`, e.g. an
     * interval. Each run sends two check-ins, when it starts and when it ends. Return the exit code from the command's
     * action: a run that ends otherwise, with `exit()`, `Yii::$app->end()` or an `ExitException`, is reported as
     * failed, as its exit code can't be known.
     */
    public $cronMonitors = [];
    /**
     * @var bool Whether to reuse a Sentry client that is already set up, e.g. by `\Sentry\init()` called before Yii
     * boots, instead of creating one from this component's configuration. The SDK options and integrations then
     * come from that client: [[dsn]], [[sentrySettings]] and the SDK integrations listed in [[integrations]] are
     * ignored, while this package's [[Integration]] is still applied. Without a client already set up, the component
     * creates one as usual.
     */
    public $useExistingHub = true;
    /**
     * @var bool|null Whether to attach the logged-in user's ID to events. By default (null), it is attached when the
     * SDK may send personal data: with `send_default_pii`, or the SDK's `data_collection` option allowing user info.
     * `true` attaches the ID without the other personal data; `false` never attaches it.
     */
    public $sendUserId;
    /**
     * @var bool Add a "yii" section to events, with the Yii version and the application's ID and name.
     */
    public $yiiContext = true;
    /**
     * @var bool Use the requested route (e.g. "site/view") as the "transaction" of error events.
     */
    public $routeAsTransaction = true;
    /**
     * @var bool Under PHP-FPM or LiteSpeed, finish the HTTP response once Yii has sent it, so that visitors don't wait
     * while events are sent to Sentry at the end of the request. Code that runs afterwards can no longer send output.
     */
    public $sendAfterResponse = true;
    /**
     * @var string|null The directory that file paths in stack traces are shown relative to. By default, the
     * directory that holds `vendor/` (the project root), or `@app` when the vendor directory is elsewhere.
     */
    public $appBasePath;

    /**
     * @var HubInterface
     */
    protected $hub;

    /**
     * @var Integration[] this package's integrations that a reused client doesn't have, applied by this component
     */
    private $manualIntegrations = [];

    /**
     * @var array<string, bool> [[tracing]], with the defaults
     */
    private $tracingSettings = Tracer::DEFAULTS;
    /**
     * @var array<string, bool> [[queue]], with the defaults
     */
    private $queueSettings = QueueListener::DEFAULTS;
    /**
     * @var array<string, array{0: string, 1: \Sentry\MonitorConfig|null}> [[cronMonitors]], as CronMonitor reads it
     */
    private $cronMonitorSettings = [];
    /**
     * @var Integration|null this package's integration, as this component configured it
     */
    private $privacyIntegration;

    /**
     * @var self|null the component whose settings apply to events, i.e. the most recently initialized one
     */
    private static $current;
    /**
     * @var Tracer|null the tracer of the application bootstrapped last
     */
    private static $tracer;
    /**
     * @var QueueListener|null the queue listener of the application bootstrapped last, when yii2-queue is installed
     */
    private static $queueListener;
    /**
     * @var CronMonitor|null the cron monitor of the console application bootstrapped last, when it lists commands
     */
    private static $cronMonitor;
    /**
     * @var ClientInterface|null the last client a component created: it is not "existing" for the next component
     */
    private static $ownClient;
    /**
     * @var bool whether the event processor is registered (it is global, so once per process)
     */
    private static $processorRegistered = false;
    /**
     * @var array<int, array{0: string, 1: string, 2: callable}> the class-level event handlers currently registered
     */
    private static $handlers = [];

    public function init()
    {
        parent::init();

        $this->tracingSettings = self::validSettings('tracing', $this->tracing, Tracer::DEFAULTS);
        $this->queueSettings = self::validSettings('queue', $this->queue, QueueListener::DEFAULTS);
        $this->cronMonitorSettings = CronMonitor::monitors($this->cronMonitors);

        $currentHub = SentrySdk::getCurrentHub();
        $existingClient = $currentHub->getClient();

        if ($this->useExistingHub && $existingClient !== null && $existingClient !== self::$ownClient) {
            $this->hub = $currentHub;
            $this->manualIntegrations = $this->integrationsMissingFrom($existingClient);
        } else {
            $defaults = array_merge([
                'send_default_pii' => false,
                'integrations' => [],
                // By default Sentry enabled ExceptionListenerIntegration, ErrorListenerIntegration and RequestIntegration
                // integrations. ExceptionListenerIntegration defines a global exception handler as well as Yii.
                // Sentry's handler is always being called first, because it was defined later then Yii's one. This leads
                // to report duplication when handling any exception, that is supposed to be handled by Yii.
                'default_integrations' => false,
            ], $this->pathOptions());
            // Left out when not configured, so that the SDK falls back to the SENTRY_DSN and SENTRY_ENVIRONMENT
            // environment variables.
            if ($this->dsn !== null) {
                $defaults['dsn'] = $this->dsn;
            }
            if (!isset($_SERVER['SENTRY_ENVIRONMENT'])) {
                $defaults['environment'] = YII_ENV;
            }

            $integrations = $this->createIntegrations();
            $settings = $this->sentrySettings;
            if (isset($settings['data_collection']) && is_array($settings['data_collection']) && $this->privacyIntegration !== null) {
                $settings['data_collection'] = self::withSecretCookiesDenied(
                    $settings['data_collection'],
                    $this->privacyIntegration->secretCookieNames()
                );
            }

            $options = new Options(array_merge($defaults, $settings));
            $options->setIntegrations($integrations);

            /** @var ClientBuilder $builder */
            $builder = Yii::$container->get(ClientBuilder::class, [$options]);
            $client = $builder->getClient();
            self::$ownClient = $client;

            SentrySdk::setCurrentHub(new Hub($client));
            $this->hub = SentrySdk::getCurrentHub();
        }

        self::$current = $this;
        self::registerEventProcessor();
    }

    /**
     * @inheritDoc
     */
    public function bootstrap($app)
    {
        // Class-level handlers live for the whole process: an application rebuilt in the same process (test suites,
        // some workers) would otherwise record everything once more for every instance.
        foreach (self::$handlers as [$class, $name, $handler]) {
            Event::off($class, $name, $handler);
        }
        self::$handlers = [];

        // Registered first: the response is finished before anything is sent to Sentry after it.
        if ($this->sendAfterResponse) {
            $this->listen(Response::class, Response::EVENT_AFTER_SEND, function (): void {
                $this->finishResponse();
            });
        }

        if (self::$tracer !== null) {
            // The previous application's request or command, if it never ended.
            self::$tracer->end();
        }
        $listen = function (string $class, string $name, callable $handler): void {
            $this->listen($class, $name, $handler);
        };
        $scrubber = function (): ?Scrubber {
            return $this->privacyScrubber();
        };
        self::$tracer = new Tracer($this->hub, $this->tracingSettings, $listen, $scrubber, $this->rootPath());
        try {
            self::$tracer->start($app);
        } catch (Throwable $exception) {
            // E.g. request headers that can't be read: the application must still start.
            Yii::warning('Unable to start Sentry tracing: ' . $exception, __METHOD__);
        }

        self::$queueListener = null;
        if (class_exists(Queue::class)) {
            self::$queueListener = new QueueListener(
                $this->hub,
                $this->queueSettings,
                $this->tracingSettings['continueTraces'],
                self::$tracer,
                $listen,
                $scrubber
            );
            self::$queueListener->start();
        }

        if (self::$cronMonitor !== null) {
            // The previous application's command, if it never ended.
            self::$cronMonitor->end();
        }
        self::$cronMonitor = null;
        if ($app instanceof ConsoleApplication && $this->cronMonitorSettings !== []) {
            self::$cronMonitor = new CronMonitor($this->hub, $this->cronMonitorSettings, $listen);
            self::$cronMonitor->start($app);
        }

        $this->listen(Controller::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event) use ($app): void {
            $action = $event->action;
            $route = $action->getUniqueId();

            $this->hub->addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_NAVIGATION,
                'route',
                $route,
                ['action' => $action instanceof InlineAction ? get_class($action->controller) . '::' . $action->actionMethod . '()' : get_class($action) . '::run()']
            ));

            // Set "route" tag to the route that was requested: actions started later in the same request, like the
            // error action or one started by Controller::run(), must not replace it.
            $requestedRoute = $app->requestedAction !== null ? $app->requestedAction->getUniqueId() : $route;
            $this->hub->configureScope(function (Scope $scope) use ($requestedRoute): void {
                $scope->setTag('route', $requestedRoute);
            });
        });
    }

    /**
     * Returns the meta tags that let Sentry's browser SDK continue this request's trace, so that what happens in the
     * browser and on the server shows as one trace. Print them in the `<head>` of your layout:
     *
     * ```php
     * <?= Yii::$app->sentry->traceMetaTags() ?>
     * ```
     *
     * Don't print them on pages that are cached as a whole: every visitor would join the same trace.
     */
    public function traceMetaTags(): string
    {
        return sprintf(
            "<meta name=\"sentry-trace\" content=\"%s\">\n<meta name=\"baggage\" content=\"%s\">",
            Html::encode(getTraceparent()),
            Html::encode(getBaggage())
        );
    }

    /**
     * Removes secrets and personal data from a line Yii itself logged, under the [[privacy]] settings.
     *
     * @internal used by [[LogTarget]]
     */
    public function scrubLogMessage(string $category, string $text): string
    {
        $client = $this->hub->getClient();

        return $this->privacyIntegration !== null && $client !== null
            ? $this->privacyIntegration->scrubLogMessage($category, $text, $client->getOptions())
            : $text;
    }

    /**
     * Whether personal data is hidden, under the [[privacy]] settings.
     *
     * @internal used by [[LogTarget]]
     */
    public function hidesPersonalData(): bool
    {
        $scrubber = $this->privacyScrubber();

        return $scrubber !== null ? $scrubber->hidesPersonalData() : !DataCollectionPolicy::fromHub($this->hub)->shouldCollectUserInfo();
    }

    /**
     * A secret, e.g. a session ID, as it may be sent: replaced under the [[privacy]] setting `scrubSecrets`.
     *
     * @internal used by [[LogTarget]]
     */
    public function scrubSecret(string $value): string
    {
        $integration = $this->privacyIntegration;

        return $integration !== null && $integration->scrubSecrets ? (string)$integration->piiReplaceText : $value;
    }

    /**
     * Whether outgoing HTTP calls are added as breadcrumbs here, so that Yii's own log lines for them aren't needed.
     *
     * @internal used by [[LogTarget]]
     */
    public function tracesHttpClient(): bool
    {
        return $this->tracingSettings['httpClient'] && class_exists(Client::class);
    }

    /**
     * Whether failed yii2-queue jobs are reported here, so that the line yii2-queue's LogBehavior logs about them
     * isn't needed.
     *
     * @internal used by [[LogTarget]]
     */
    public function reportsQueueErrors(): bool
    {
        return $this->queueSettings['errors'] && class_exists(Queue::class);
    }

    /**
     * Whether queuing a job is a breadcrumb, so that the lines yii2-queue's LogBehavior logs aren't needed.
     *
     * @internal used by [[LogTarget]]
     */
    public function addsQueueBreadcrumbs(): bool
    {
        return $this->queueSettings['breadcrumbs'] && class_exists(Queue::class);
    }

    public function getHub(): HubInterface
    {
        return $this->hub;
    }

    /**
     * Ends the HTTP response, so that the visitor doesn't wait while events are sent to Sentry.
     */
    protected function finishResponse(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /**
     * @return IntegrationInterface[]
     *
     * @throws InvalidConfigException if an item does not create a Sentry integration, or [[privacy]] has a setting
     * the integration doesn't have
     */
    protected function createIntegrations(): array
    {
        $integrations = [];

        foreach ($this->integrations as $item) {
            $integration = is_object($item) && !$item instanceof Closure ? $item : Yii::createObject($item);

            if (!$integration instanceof IntegrationInterface) {
                throw new InvalidConfigException(sprintf(
                    'Sentry integrations must implement %s, %s given.',
                    IntegrationInterface::class,
                    get_class($integration)
                ));
            }

            if ($integration instanceof Integration) {
                $this->applyPrivacySettings($integration);
                $this->privacyIntegration = $this->privacyIntegration ?? $integration;
            }

            $integrations[] = $integration;
        }

        return $integrations;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, bool> $defaults
     *
     * @return array<string, bool> the settings, with the defaults for the ones not given
     *
     * @throws InvalidConfigException on a setting that doesn't exist, e.g. a misspelled one
     */
    private static function validSettings(string $kind, array $settings, array $defaults): array
    {
        foreach (array_keys($settings) as $name) {
            if (!array_key_exists($name, $defaults)) {
                throw new InvalidConfigException(sprintf('Unknown Sentry %s setting "%s".', $kind, $name));
            }
        }

        return array_map('boolval', array_merge($defaults, $settings));
    }

    /**
     * Adds Yii's cookies that hold secrets to the cookies the SDK leaves out under its `data_collection` option: the
     * SDK collects them itself then, leaving out the ones named like its own list of words, which a name like
     * "advanced-frontend" for the session cookie isn't. A setting that only lets listed cookies through, or none, is
     * kept as it is.
     *
     * @param array<array-key, mixed> $dataCollection
     * @param string[] $names
     *
     * @return array<array-key, mixed>
     */
    private static function withSecretCookiesDenied(array $dataCollection, array $names): array
    {
        if ($names === []) {
            return $dataCollection;
        }

        $cookies = $dataCollection['cookies'] ?? [];
        if ($cookies instanceof KeyValueCollectionBehavior) {
            if ($cookies->getMode() === KeyValueCollectionBehavior::MODE_DENY_LIST) {
                $dataCollection['cookies'] = KeyValueCollectionBehavior::denyList(array_values(array_unique(array_merge($cookies->getTerms(), $names))));
            }

            return $dataCollection;
        }

        // Without a mode, the SDK takes a deny list.
        if (is_array($cookies) && ($cookies['mode'] ?? KeyValueCollectionBehavior::MODE_DENY_LIST) === KeyValueCollectionBehavior::MODE_DENY_LIST) {
            $terms = $cookies['terms'] ?? [];
            if (is_array($terms)) {
                $cookies['terms'] = array_values(array_unique(array_merge($terms, $names)));
                $dataCollection['cookies'] = $cookies;
            }
        }

        return $dataCollection;
    }

    private function privacyScrubber(): ?Scrubber
    {
        $client = $this->hub->getClient();

        return $this->privacyIntegration !== null && $client !== null
            ? $this->privacyIntegration->createScrubber($client->getOptions())
            : null;
    }

    /**
     * @throws InvalidConfigException on a setting the integration doesn't have, e.g. a misspelled one
     */
    private function applyPrivacySettings(Integration $integration): void
    {
        $class = new ReflectionClass($integration);

        foreach ($this->privacy as $name => $value) {
            $name = (string)$name;
            if (!$class->hasProperty($name) || !$class->getProperty($name)->isPublic() || $class->getProperty($name)->isStatic()) {
                throw new InvalidConfigException(sprintf('Unknown Sentry privacy setting "%s".', $name));
            }

            $integration->$name = $value;
        }
    }

    /**
     * Registers a class-level event handler, remembering it so that a later bootstrap can remove it.
     *
     * The handler runs inside the application's own work, e.g. while an action starts or a response is sent: a
     * failure in it must not break that work, so it is logged as a warning instead.
     *
     * @param callable $handler
     */
    private function listen(string $class, string $name, $handler): void
    {
        $guarded = static function ($event) use ($handler, $name): void {
            try {
                $handler($event);
            } catch (Throwable $exception) {
                // Not __METHOD__: inside a closure it is "{closure:...}" on PHP 8.4+, which LogTarget would not exclude.
                Yii::warning(sprintf('Unable to handle the "%s" event for Sentry: %s', $name, $exception), self::class);
            }
        };

        Event::on($class, $name, $guarded);
        self::$handlers[] = [$class, $name, $guarded];
    }

    /**
     * This package's integrations that a client set up elsewhere doesn't have; they are applied by this component.
     *
     * @return Integration[]
     */
    private function integrationsMissingFrom(ClientInterface $client): array
    {
        $missing = [];
        foreach ($this->createIntegrations() as $integration) {
            if ($integration instanceof Integration && $client->getIntegration(get_class($integration)) === null) {
                $missing[] = $integration;
            }
        }

        return $missing;
    }

    /**
     * Shows file paths relative to the project root, and marks vendor code as not being the application's.
     *
     * @return array<string, string[]>
     */
    private function pathOptions(): array
    {
        $vendor = Yii::getAlias('@vendor', false);

        $options = ['prefixes' => self::withRealPaths([$this->rootPath()])];
        if (is_string($vendor)) {
            $options['in_app_exclude'] = self::withRealPaths([$vendor]);
        }

        return $options;
    }

    /**
     * The directory that file paths are shown relative to: [[appBasePath]], or the project root.
     */
    private function rootPath(): string
    {
        return $this->appBasePath !== null
            ? (string)Yii::getAlias($this->appBasePath)
            : self::projectRoot(Yii::getAlias('@vendor', false));
    }

    private static function registerEventProcessor(): void
    {
        if (self::$processorRegistered) {
            return;
        }

        Scope::addGlobalEventProcessor(static function (SentryEvent $event): SentryEvent {
            return self::$current !== null ? self::$current->processEvent($event) : $event;
        });
        self::$processorRegistered = true;
    }

    /**
     * Adds what Yii knows to an event: request data for a reused client, the route, the Yii section and the user.
     */
    private function processEvent(SentryEvent $event): SentryEvent
    {
        $type = (string)$event->getType();
        if (($type !== 'event' && $type !== 'transaction') || SentrySdk::getCurrentHub() !== $this->hub) {
            return $event;
        }

        try {
            $client = $this->hub->getClient();
            foreach ($this->manualIntegrations as $integration) {
                if ($client !== null) {
                    $event = $integration->processEvent($event, $client->getOptions());
                }
            }

            if (self::$queueListener !== null) {
                $event = self::$queueListener->processEvent($event);
            }

            if ($type === 'event' && self::$tracer !== null) {
                // Calls that failed before the event: the breadcrumbs were taken from the scope already.
                $failedCalls = self::$tracer->reportFailedHttpCalls();
                if ($failedCalls !== []) {
                    $event->setBreadcrumb(array_merge($event->getBreadcrumbs(), $failedCalls));
                }
            }

            $app = Yii::$app;
            if ($app !== null) {
                if ($type === 'event' && $this->routeAsTransaction) {
                    $this->setRouteAsTransaction($event, $app);
                }
                if ($this->yiiContext) {
                    $event->setContext('yii', [
                        'version' => Yii::getVersion(),
                        'app_id' => $app->id,
                        'app_name' => $app->name,
                    ]);
                }
                $this->addUserId($event, $app);
            }
        } catch (Throwable $exception) {
            Yii::warning('Unable to add Yii details to the Sentry event: ' . $exception, __METHOD__);
        }

        return $event;
    }

    private function setRouteAsTransaction(SentryEvent $event, Application $app): void
    {
        // While the request is traced, its errors take the transaction's name, so that Sentry links the two.
        $traced = $this->hub->getTransaction();
        if ($traced !== null) {
            if ($event->getTransaction() === null) {
                $event->setTransaction($traced->getName());
            }

            return;
        }

        // An error while a queue job runs, e.g. in a worker, which runs one route for all its jobs.
        $job = self::$queueListener !== null ? self::$queueListener->currentJobName() : null;
        if ($job !== null) {
            if ($event->getTransaction() === null) {
                $event->setTransaction($job);
            }

            return;
        }

        $action = $app->requestedAction;
        if ($action === null) {
            return;
        }

        // A reused client may have the SDK's TransactionIntegration, which sets the URL path instead.
        $transaction = $event->getTransaction();
        if ($transaction === null || $transaction === ($_SERVER['PATH_INFO'] ?? null)) {
            $event->setTransaction($action->getUniqueId());
        }
    }

    private function addUserId(SentryEvent $event, Application $app): void
    {
        if (!$this->shouldSendUserId() || !$app->has('user', true)) {
            return;
        }

        $user = $app->get('user');
        // Without renewing: never start a session or query the database while an error is being reported.
        $identity = $user instanceof User ? $user->getIdentity(false) : null;
        $id = $identity !== null ? self::toSentryUserId($identity->getId()) : null;
        if ($id === null) {
            return;
        }

        $userData = $event->getUser();
        if ($userData === null) {
            $event->setUser(UserDataBag::createFromUserIdentifier($id));
        } elseif ($userData->getId() === null) {
            $userData->setId($id);
        }
    }

    private function shouldSendUserId(): bool
    {
        if ($this->sendUserId !== null) {
            return (bool)$this->sendUserId;
        }

        // With send_default_pii, or the data_collection option's user_info when that is set.
        return DataCollectionPolicy::fromHub($this->hub)->shouldCollectUserInfo();
    }

    /**
     * @param string|false $vendor
     */
    private static function projectRoot($vendor): string
    {
        $app = (string)Yii::getAlias('@app');

        if (is_string($vendor)) {
            $root = dirname($vendor);
            if ($app === $root || strpos($app, $root . DIRECTORY_SEPARATOR) === 0) {
                return $root;
            }
        }

        return $app;
    }

    /**
     * @param string[] $paths
     *
     * @return string[] the paths, plus their resolved forms when they differ (e.g. behind a symlink)
     */
    private static function withRealPaths(array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            $result[] = $path;
            $real = realpath($path);
            if ($real !== false && $real !== $path) {
                $result[] = $real;
            }
        }

        return $result;
    }

    /**
     * The SDK only accepts integer and string user IDs.
     *
     * @param mixed $id an identity's ID. IdentityInterface documents `string|int`, but implementations may return
     *                  other values, like MongoDB's ObjectId; stringable objects are converted to strings.
     *
     * @return int|string|null the ID to send, or null if it has no usable form
     */
    private static function toSentryUserId($id)
    {
        if (is_object($id) && method_exists($id, '__toString')) {
            return (string)$id;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }
}
