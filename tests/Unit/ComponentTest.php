<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\Component;
use devnullius\yii2\sentry\Integration;
use devnullius\yii2\sentry\tests\Support\FinishRecordingComponent;
use devnullius\yii2\sentry\tests\Support\RecordingTransport;
use devnullius\yii2\sentry\tests\Support\TestCase;
use ReflectionClass;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\SentrySdk;
use Throwable;
use Yii;
use yii\base\Action;
use yii\web\Controller;
use yii\web\Response;

use function Sentry\init;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ComponentTest extends TestCase
{
    public function testIntegrationsAcceptYiiArrayConfiguration(): void
    {
        // The shape the README documents for configuring PII filtering.
        $app = $this->createWebApp(['integrations' => [[
            'class' => Integration::class,
            'piiHeaders' => ['custom-token-header'],
            'piiReplaceText' => '[X]',
        ]]]);

        $integration = $app->get('sentry')->getHub()->getIntegration(Integration::class);

        $this->assertInstanceOf(Integration::class, $integration);
        $this->assertSame(['custom-token-header'], $integration->piiHeaders);
        $this->assertSame('[X]', $integration->piiReplaceText);
    }

    public function testDsnFallsBackToTheSentryDsnEnvironmentVariable(): void
    {
        $_SERVER['SENTRY_DSN'] = 'https://key@o1.ingest.sentry.example.com/123';

        $app = $this->createWebApp(['dsn' => null]);

        $this->assertSame(
            'https://key@o1.ingest.sentry.example.com/123',
            (string)$app->get('sentry')->getHub()->getClient()->getOptions()->getDsn()
        );
    }

    public function testConfiguredDsnTakesPrecedenceOverTheEnvironmentVariable(): void
    {
        $_SERVER['SENTRY_DSN'] = 'https://key@o1.ingest.sentry.example.com/123';

        $app = $this->createWebApp(['dsn' => 'https://other@sentry.example.com/7']);

        $this->assertSame(
            'https://other@sentry.example.com/7',
            (string)$app->get('sentry')->getHub()->getClient()->getOptions()->getDsn()
        );
    }

    public function testEnvironmentFallsBackToTheSentryEnvironmentVariable(): void
    {
        $_SERVER['SENTRY_ENVIRONMENT'] = 'staging';

        $app = $this->createWebApp();

        $this->assertSame('staging', $app->get('sentry')->getHub()->getClient()->getOptions()->getEnvironment());
    }

    public function testEnvironmentDefaultsToYiiEnv(): void
    {
        $app = $this->createWebApp();

        $this->assertSame(YII_ENV, $app->get('sentry')->getHub()->getClient()->getOptions()->getEnvironment());
    }

    public function testEnvironmentFromSentrySettingsWins(): void
    {
        $_SERVER['SENTRY_ENVIRONMENT'] = 'staging';

        $app = $this->createWebApp(['sentrySettings' => ['environment' => 'qa']]);

        $this->assertSame('qa', $app->get('sentry')->getHub()->getClient()->getOptions()->getEnvironment());
    }

    public function testCanReuseAHubInitializedBeforeYii(): void
    {
        $transport = new RecordingTransport();
        init(['dsn' => 'https://public@sentry.example.com/1', 'transport' => $transport, 'default_integrations' => false]);
        $client = SentrySdk::getCurrentHub()->getClient();

        $app = $this->createWebApp(['useExistingHub' => true]);
        $hub = $app->get('sentry')->getHub();
        $hub->captureMessage('hello');

        $this->assertSame($client, $hub->getClient(), 'The client created by \Sentry\init() must be kept.');
        $events = $transport->events();
        $this->assertCount(1, $events);
        $this->assertSame('GET', $events[0]['request']['method'] ?? null, 'Yii request data must still be added.');
    }

    public function testReusesAClientSetUpBeforeYiiByDefault(): void
    {
        init(['dsn' => 'https://public@sentry.example.com/1', 'transport' => new RecordingTransport()]);
        $client = SentrySdk::getCurrentHub()->getClient();

        $app = $this->createWebApp();

        $this->assertSame($client, $app->get('sentry')->getHub()->getClient());
    }

    public function testCanStillCreateItsOwnClient(): void
    {
        init(['dsn' => 'https://public@sentry.example.com/1', 'transport' => new RecordingTransport()]);
        $client = SentrySdk::getCurrentHub()->getClient();

        $app = $this->createWebApp(['useExistingHub' => false]);

        $this->assertNotSame($client, $app->get('sentry')->getHub()->getClient());
    }

    public function testPhpErrorsAreLeftToYiisErrorHandlerByDefault(): void
    {
        // Yii's error handler and LogTarget report PHP warnings and fatal errors; the SDK's listeners would report
        // each one a second time.
        $defaults = (new ReflectionClass(Component::class))->getDefaultProperties()['integrations'];

        $this->assertNotContains(ErrorListenerIntegration::class, $defaults);
        $this->assertNotContains(FatalErrorListenerIntegration::class, $defaults);
    }

    public function testEventsGetAYiiSection(): void
    {
        $app = $this->createWebApp();

        $app->get('sentry')->getHub()->captureMessage('hello');

        $this->assertSame(
            ['version' => Yii::getVersion(), 'app_id' => 'unit-test', 'app_name' => 'My Application'],
            $this->singleEvent()['contexts']['yii'] ?? null
        );
    }

    public function testTheYiiSectionCanBeTurnedOff(): void
    {
        $app = $this->createWebApp(['yiiContext' => false]);

        $app->get('sentry')->getHub()->captureMessage('hello');

        $this->assertArrayNotHasKey('yii', $this->singleEvent()['contexts'] ?? []);
    }

    public function testTheRouteIsTheEventsTransaction(): void
    {
        $app = $this->createWebApp();
        $app->requestedAction = new Action('view', new Controller('site', $app));

        $app->get('sentry')->getHub()->captureMessage('hello');

        $this->assertSame('site/view', $this->singleEvent()['transaction'] ?? null);
    }

    public function testTheRouteAsTransactionCanBeTurnedOff(): void
    {
        $app = $this->createWebApp(['routeAsTransaction' => false]);
        $app->requestedAction = new Action('view', new Controller('site', $app));

        $app->get('sentry')->getHub()->captureMessage('hello');

        $this->assertNull($this->singleEvent()['transaction'] ?? null);
    }

    public function testEventHandlersAreRegisteredOnlyOncePerProcess(): void
    {
        // E.g. a test suite, or a worker that rebuilds the application for every job.
        $tracing = ['sentrySettings' => ['traces_sample_rate' => 1.0], 'useExistingHub' => false];
        $this->createWebApp($tracing);
        $app = $this->createWebApp($tracing);

        $app->runAction('site/index');
        $app->getResponse()->trigger(Response::EVENT_AFTER_SEND);

        $transactions = $this->transactions();
        $this->assertCount(1, $transactions);
        $actions = array_filter($transactions[0]['spans'] ?? [], static function (array $span): bool {
            return ($span['op'] ?? null) === 'yii.action';
        });
        $this->assertCount(1, $actions, 'The action is traced once, not once per application built.');
    }

    public function testSendsToSentryAfterTheResponseHasBeenSent(): void
    {
        $app = $this->createWebApp(['class' => FinishRecordingComponent::class]);

        $app->getResponse()->trigger(Response::EVENT_AFTER_SEND);

        $this->assertSame(1, $app->get('sentry')->finishedResponses);
    }

    public function testSendingAfterTheResponseCanBeTurnedOff(): void
    {
        $app = $this->createWebApp(['class' => FinishRecordingComponent::class, 'sendAfterResponse' => false]);

        $app->getResponse()->trigger(Response::EVENT_AFTER_SEND);

        $this->assertSame(0, $app->get('sentry')->finishedResponses);
    }

    public function testVendorCodeIsNotMarkedAsApplicationCode(): void
    {
        $app = $this->createWebApp();

        try {
            Yii::createObject('No\\Such\\ClassName'); // throws from inside vendor/yiisoft/yii2
        } catch (Throwable $exception) {
            $app->get('sentry')->getHub()->captureException($exception);
        }

        $frames = $this->singleEvent()['exception']['values'][0]['stacktrace']['frames'] ?? [];
        $inApp = [];
        foreach ($frames as $frame) {
            $inApp[$frame['filename']] = $frame['in_app'];
        }
        $this->assertFalse($inApp['/vendor/yiisoft/yii2/di/Container.php'] ?? null, 'Paths are relative to the project root.');
        $this->assertTrue($inApp['/tests/Unit/ComponentTest.php'] ?? null);
    }
}
