<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use devnullius\yii2\sentry\Component;
use devnullius\yii2\sentry\Integration;
use Yii;
use yii\web\Application;

/**
 * Base for in-process tests. Builds a Yii web application (in CLI, with the request marked as a web request) whose
 * Sentry component records events instead of sending them.
 *
 * Test classes should run each test in a separate process: the SDK and Yii keep process-wide static state.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @var RecordingTransport
     */
    protected $transport;

    protected function tearDown(): void
    {
        Yii::$app = null;
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $sentry     component config overrides; "integrations" replaces the list and
     *                                         "sentrySettings" is merged over the recording transport
     * @param array<string, mixed> $server     $_SERVER entries describing the request
     * @param array<string, mixed> $components extra components; "request" is merged over the defaults
     */
    protected function createWebApp(array $sentry = [], array $server = [], array $components = []): Application
    {
        $_SERVER = array_merge($_SERVER, [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/site/index',
            'QUERY_STRING' => '',
            'HTTP_HOST' => 'example.com',
            'SERVER_NAME' => 'example.com',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '203.0.113.10',
        ], $server);

        $this->transport = new RecordingTransport();

        $sentrySettings = $sentry['sentrySettings'] ?? [];
        unset($sentry['sentrySettings']);
        $sentryConfig = array_merge([
            'class' => Component::class,
            'dsn' => 'https://public@sentry.example.com/1',
            // No SDK error listeners in-process: they would install global handlers inside PHPUnit.
            'integrations' => [Integration::class],
        ], $sentry);
        $sentryConfig['sentrySettings'] = array_merge(['transport' => $this->transport], $sentrySettings);

        $request = array_merge([
            'cookieValidationKey' => 'test',
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
            'scriptUrl' => '/index.php',
        ], $components['request'] ?? []);
        unset($components['request']);

        $app = new Application([
            'id' => 'unit-test',
            'basePath' => dirname(__DIR__) . '/Fixtures/WebApp',
            'vendorPath' => dirname(__DIR__, 2) . '/vendor',
            'runtimePath' => sys_get_temp_dir(),
            'bootstrap' => ['sentry'],
            'components' => array_merge([
                'request' => $request,
                'user' => ['identityClass' => Identity::class, 'enableSession' => false],
                'sentry' => $sentryConfig,
            ], $components),
        ]);
        $app->getRequest()->setIsConsoleRequest(false);

        return $app;
    }

    /**
     * Describes a form POST in $_SERVER/$_POST; pass the result as createWebApp()'s $server argument.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, string>
     */
    protected function formPost(array $fields, string $uri = '/site/post-log'): array
    {
        $_POST = $fields;

        return [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $uri,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function singleEvent(): array
    {
        $events = $this->transport->events();
        $this->assertCount(1, $events, 'Expected exactly one event to be sent to Sentry.');

        return $events[0];
    }
}
