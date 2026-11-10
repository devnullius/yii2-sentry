<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\Integration;
use devnullius\yii2\sentry\tests\Support\RecordingTransport;
use devnullius\yii2\sentry\tests\Support\TestCase;
use Sentry\SentrySdk;

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

    public function testReplacesTheCurrentHubByDefault(): void
    {
        init(['dsn' => 'https://public@sentry.example.com/1', 'transport' => new RecordingTransport()]);
        $client = SentrySdk::getCurrentHub()->getClient();

        $app = $this->createWebApp();

        $this->assertNotSame($client, $app->get('sentry')->getHub()->getClient());
    }
}
