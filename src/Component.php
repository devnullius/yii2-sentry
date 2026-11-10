<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\FrameContextifierIntegration;
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
use Yii;
use yii\base\ActionEvent;
use yii\base\BootstrapInterface;
use yii\base\Controller;
use yii\base\Event;
use yii\base\InlineAction;
use yii\httpclient\Client;
use yii\httpclient\RequestEvent;
use yii\web\User;
use yii\web\UserEvent;
use function Sentry\startTransaction;

class Component extends \yii\base\Component implements BootstrapInterface
{
    /**
     * @var string Sentry client key.
     */
    public $dsn;
    /**
     * @var array
     */
    public $sentrySettings = [];
    /**
     * @var array
     */
    public $integrations = [
        Integration::class,
        FatalErrorListenerIntegration::class,
        TransactionIntegration::class,
        FrameContextifierIntegration::class,
        ErrorListenerIntegration::class,

        EnvironmentIntegration::class,
        //ExceptionListenerIntegration::class,
        FrameContextifierIntegration::class,
        ModulesIntegration::class,
        RequestIntegration::class,
    ];
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

        $basePath = Yii::getAlias($this->appBasePath);

        $options = new Options(array_merge([
            'dsn' => $this->dsn,
            'send_default_pii' => false,
            'environment' => YII_ENV,
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
        ], $this->sentrySettings));

        $integrations = [];
        foreach ($this->integrations as $item) {
            if (is_object($item)) {
                $integrations[] = $item;
            } else {
                $integrations[] = new $item();
            }
        }

        $options->setIntegrations($integrations);

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
        Event::on(User::class, User::EVENT_AFTER_LOGIN, function (UserEvent $event) {
            $this->hub->configureScope(function (Scope $scope) use ($event): void {
                $scope->setUser([
                    'id' => $event->identity->getId(),
                ]);
            });
        });

        //Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event) use ($app) {
        //    $route = $event->action->getUniqueId();
        //
        //    $metadata = [];
        //    // Retrieve action's function
        //    if ($app->requestedAction instanceof InlineAction) {
        //        $metadata['action'] = get_class($app->requestedAction->controller) . '::' . $app->requestedAction->actionMethod . '()';
        //    } else {
        //        $metadata['action'] = get_class($app->requestedAction) . '::run()';
        //    }
        //
        //    // Set breadcrumb
        //    $this->hub->addBreadcrumb(new Breadcrumb(
        //        Breadcrumb::LEVEL_INFO,
        //        Breadcrumb::TYPE_NAVIGATION,
        //        'route',
        //        $route,
        //        $metadata
        //    ));
        //
        //    // Set "route" tag
        //    $this->hub->configureScope(function (Scope $scope) use ($route): void {
        //        $scope->setTag('route', $route);
        //    });
        //});

        $transaction = null;
        $span = null;

        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event) use ($app, &$transaction, &$span) {
            $route = $event->action->getUniqueId();

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
            if ($app->requestedAction instanceof InlineAction) {
                $metadata['action'] = get_class($app->requestedAction->controller) . '::' . $app->requestedAction->actionMethod . '()';
            } else {
                $metadata['action'] = get_class($app->requestedAction) . '::run()';
            }

            // Set breadcrumb
            $this->hub->addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_NAVIGATION,
                'route',
                $route,
                $metadata
            ));

            // Set "route" tag
            $this->hub->configureScope(function (Scope $scope) use ($route): void {
                $scope->setTag('route', $route);
            });
        });

        Event::on(Controller::class, Controller::EVENT_AFTER_ACTION, function (ActionEvent $event) use ($app, &$transaction, &$span) {
            $span->finish();

            // Set the current span back to the transaction since we just finished the previous span
            SentrySdk::getCurrentHub()->setSpan($transaction);

            // Finish the transaction, this submits the transaction and it's span to Sentry
            $transaction->finish();
        });

        if (class_exists(Client::class)) {
            $httpClientTransaction = null;
            $httpClientSpan = null;
            Event::on(Client::class, Client::EVENT_BEFORE_SEND, function (RequestEvent $event) use ($app, &$httpClientTransaction, &$httpClientSpan) {
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

            Event::on(Client::class, Client::EVENT_AFTER_SEND, function (RequestEvent $event) use ($app, &$httpClientTransaction, &$httpClientSpan) {
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
}
