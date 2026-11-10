<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Closure;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event as SentryEvent;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\Integration\ModulesIntegration;
use Sentry\Integration\RequestIntegration;
use Sentry\Integration\TransactionIntegration;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use Throwable;
use Yii;
use yii\base\ActionEvent;
use yii\base\BootstrapInterface;
use yii\base\Controller;
use yii\base\Event;
use yii\base\InlineAction;
use yii\base\InvalidConfigException;
use yii\httpclient\Client;
use yii\httpclient\RequestEvent;
use yii\web\User;
use yii\web\UserEvent;
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
     */
    public $integrations = [
        Integration::class,
        FatalErrorListenerIntegration::class,
        TransactionIntegration::class,
        FrameContextifierIntegration::class,
        ErrorListenerIntegration::class,
        EnvironmentIntegration::class,
        ModulesIntegration::class,
        RequestIntegration::class,
    ];
    /**
     * @var bool Whether to reuse a Sentry client that is already set up, e.g. by `\Sentry\init()` called before Yii
     * boots, instead of creating one from this component's configuration. The SDK options and integrations then
     * come from that client: [[dsn]], [[sentrySettings]] and the SDK integrations listed in [[integrations]] are
     * ignored, while this package's [[Integration]] is still applied. Without a client already set up, the component
     * creates one as usual.
     */
    public $useExistingHub = false;
    /**
     * @var string
     */
    public $appBasePath = '@app';

    /**
     * @var HubInterface
     */
    protected $hub;

    public function init()
    {
        parent::init();

        $currentHub = SentrySdk::getCurrentHub();
        if ($this->useExistingHub && $currentHub->getClient() !== null) {
            $this->hub = $currentHub;
            $this->addIntegrationsToExistingClient();

            return;
        }

        $basePath = Yii::getAlias($this->appBasePath);

        $defaults = [
            'send_default_pii' => false,
            'prefixes' => [
                $basePath,
            ],
            'in_app_include' => [
                $basePath,
            ],
            'in_app_exclude' => [
                $basePath . '/vendor/',
            ],
            'integrations' => [],
            // By default Sentry enabled ExceptionListenerIntegration, ErrorListenerIntegration and RequestIntegration
            // integrations. ExceptionListenerIntegration defines a global exception handler as well as Yii.
            // Sentry's handler is always being called first, because it was defined later then Yii's one. This leads
            // to report duplication when handling any exception, that is supposed to be handled by Yii.
            'default_integrations' => false,
        ];
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

        SentrySdk::setCurrentHub(new Hub($builder->getClient()));

        $this->hub = SentrySdk::getCurrentHub();
    }

    /**
     * @inheritDoc
     */
    public function bootstrap($app)
    {
        Event::on(User::class, User::EVENT_AFTER_LOGIN, function (UserEvent $event): void {
            $this->setUserFromIdentity($event);
        });

        $transaction = null;
        $span = null;

        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event) use ($app, &$transaction, &$span) {
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

        Event::on(Controller::class, Controller::EVENT_AFTER_ACTION, function (ActionEvent $event) use (&$transaction, &$span) {
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
            Event::on(Client::class, Client::EVENT_BEFORE_SEND, function (RequestEvent $event) use (&$httpClientTransaction, &$httpClientSpan) {
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

            Event::on(Client::class, Client::EVENT_AFTER_SEND, function (RequestEvent $event) use (&$httpClientTransaction, &$httpClientSpan) {
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
     * @return IntegrationInterface[]
     *
     * @throws InvalidConfigException if an item does not create a Sentry integration
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

            $integrations[] = $integration;
        }

        return $integrations;
    }

    /**
     * Applies this package's integrations to a client set up elsewhere, through an event processor on the current
     * scope. SDK integrations are left to the code that set up the client.
     */
    private function addIntegrationsToExistingClient(): void
    {
        $client = $this->hub->getClient();
        if ($client === null) {
            return;
        }

        $options = $client->getOptions();

        foreach ($this->createIntegrations() as $integration) {
            if (!$integration instanceof Integration || $client->getIntegration(get_class($integration)) !== null) {
                continue;
            }

            $this->hub->configureScope(static function (Scope $scope) use ($integration, $options): void {
                $scope->addEventProcessor(static function (SentryEvent $event) use ($integration, $options): SentryEvent {
                    return $integration->processEvent($event, $options);
                });
            });
        }
    }

    private function setUserFromIdentity(UserEvent $event): void
    {
        $id = $event->identity !== null ? self::toSentryUserId($event->identity->getId()) : null;

        if ($id === null) {
            return;
        }

        try {
            $this->hub->configureScope(static function (Scope $scope) use ($id): void {
                $scope->setUser(['id' => $id]);
            });
        } catch (Throwable $exception) {
            // Reporting must never make a login fail.
            Yii::warning('Unable to set the Sentry user: ' . $exception, __METHOD__);
        }
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
