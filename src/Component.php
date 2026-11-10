<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Closure;
use ReflectionClass;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
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
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
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
use yii\httpclient\Client;
use yii\httpclient\RequestEvent;
use yii\web\Response;
use yii\web\User;
use function Sentry\startTransaction;

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
     * @var self|null the component whose settings apply to events, i.e. the most recently initialized one
     */
    private static $current;
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

            $options = new Options(array_merge($defaults, $this->sentrySettings));
            $options->setIntegrations($this->createIntegrations());

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

        if ($this->sendAfterResponse) {
            $this->listen(Response::class, Response::EVENT_AFTER_SEND, function (): void {
                $this->finishResponse();
            });
        }

        $transaction = null;
        $span = null;

        $this->listen(Controller::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event) use ($app, &$transaction, &$span) {
            $action = $event->action;
            $route = $action->getUniqueId();

            $transactionContext = new TransactionContext();
            $transactionContext->setName($event->sender->id);
            $transactionContext->setOp($route);

            $transaction = startTransaction($transactionContext);

            // Set the current transaction as the current span so we can retrieve it later
            SentrySdk::getCurrentHub()->setSpan($transaction);

            // Setup the context for the expensive operation span
            $spanContext = new SpanContext();
            $spanContext->setOp($route);

            // Start the span
            $span = $transaction->startChild($spanContext);

            // Set the current span to the span we just started
            SentrySdk::getCurrentHub()->setSpan($span);

            $metadata = [];
            // Retrieve action's function
            if ($action instanceof InlineAction) {
                $metadata['action'] = get_class($action->controller) . '::' . $action->actionMethod . '()';
            } else {
                $metadata['action'] = get_class($action) . '::run()';
            }

            // Set breadcrumb
            $this->hub->addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_NAVIGATION,
                'route',
                $route,
                $metadata
            ));

            // Set "route" tag to the route that was requested: actions started later in the same request, like the
            // error action or one started by Controller::run(), must not replace it.
            $requestedRoute = $app->requestedAction !== null ? $app->requestedAction->getUniqueId() : $route;
            $this->hub->configureScope(function (Scope $scope) use ($requestedRoute): void {
                $scope->setTag('route', $requestedRoute);
            });
        });

        $this->listen(Controller::class, Controller::EVENT_AFTER_ACTION, function (ActionEvent $event) use (&$transaction, &$span) {
            // Nothing was started if our "before" handler did not run, e.g. because another handler marked the
            // event as handled.
            if ($span === null || $transaction === null) {
                return;
            }

            $span->finish();

            // Set the current span back to the transaction since we just finished the previous span
            SentrySdk::getCurrentHub()->setSpan($transaction);

            // Finish the transaction, this submits the transaction and it's span to Sentry
            $transaction->finish();
        });

        if (class_exists(Client::class)) {
            $httpClientTransaction = null;
            $httpClientSpan = null;
            $this->listen(Client::class, Client::EVENT_BEFORE_SEND, function (RequestEvent $event) use (&$httpClientTransaction, &$httpClientSpan) {
                $transactionContext = new TransactionContext();
                $transactionContext->setName($event->sender->baseUrl ?? 'remote.http.call');
                $transactionContext->setOp($event->request->fullUrl ?? 'remote.http.url');

                $httpClientTransaction = startTransaction($transactionContext);

                // Set the current transaction as the current span so we can retrieve it later
                SentrySdk::getCurrentHub()->setSpan($httpClientTransaction);

                // Setup the context for the expensive operation span
                $spanContext = new SpanContext();
                $spanContext->setOp($event->request->fullUrl ?? 'remote.http.url');

                // Start the span
                $httpClientSpan = $httpClientTransaction->startChild($spanContext);

                // Set the current span to the span we just started
                SentrySdk::getCurrentHub()->setSpan($httpClientSpan);
            });

            $this->listen(Client::class, Client::EVENT_AFTER_SEND, function (RequestEvent $event) use (&$httpClientTransaction, &$httpClientSpan) {
                // Nothing was started if our "before" handler did not run.
                if ($httpClientSpan === null || $httpClientTransaction === null) {
                    return;
                }

                $httpClientSpan->finish();

                // Set the current span back to the transaction since we just finished the previous span
                SentrySdk::getCurrentHub()->setSpan($httpClientTransaction);

                // Finish the transaction, this submits the transaction and it's span to Sentry
                $httpClientTransaction->finish();
            });
        }
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
            }

            $integrations[] = $integration;
        }

        return $integrations;
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
     * @param callable $handler
     */
    private function listen(string $class, string $name, $handler): void
    {
        Event::on($class, $name, $handler);
        self::$handlers[] = [$class, $name, $handler];
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
        $root = $this->appBasePath !== null ? (string)Yii::getAlias($this->appBasePath) : self::projectRoot($vendor);

        $options = ['prefixes' => self::withRealPaths([$root])];
        if (is_string($vendor)) {
            $options['in_app_exclude'] = self::withRealPaths([$vendor]);
        }

        return $options;
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

        $client = $this->hub->getClient();
        if ($client === null) {
            return false;
        }

        $options = $client->getOptions();
        $dataCollection = $options->getDataCollection();

        return $dataCollection !== null ? $dataCollection->shouldCollectUserInfo() : $options->shouldSendDefaultPii();
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
