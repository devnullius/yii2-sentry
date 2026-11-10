<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\Integration;
use devnullius\yii2\sentry\tests\Support\SubIntegration;
use devnullius\yii2\sentry\tests\Support\TestCase;
use Sentry\State\Scope;
use Yii;
use yii\base\Action;
use yii\log\Logger;
use yii\web\Application;
use yii\web\Controller;
use yii\web\JsonParser;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class IntegrationTest extends TestCase
{
    public function testSendDefaultPiiAddsTheClientIpAndCookies(): void
    {
        $_COOKIE = ['theme' => 'dark'];
        $app = $this->createWebApp(['sentrySettings' => ['send_default_pii' => true]], ['HTTP_COOKIE' => 'theme=dark']);

        $app->get('sentry')->getHub()->captureMessage('hello');

        $event = $this->singleEvent();
        $this->assertSame('203.0.113.10', $event['user']['ip_address'] ?? null);
        $this->assertSame(['theme' => 'dark'], $event['request']['cookies'] ?? null);
    }

    public function testExtrasSetElsewhereSurviveRequestBodyProcessing(): void
    {
        $app = $this->createWebApp([], $this->formPost(['username' => 'alice']));
        $app->getRequest()->setRawBody('username=alice');
        $this->runningAction($app, 'post-log');
        $hub = $app->get('sentry')->getHub();

        $hub->withScope(static function (Scope $scope) use ($hub): void {
            $scope->setExtra('from_scope', 'kept');
            $hub->captureMessage('hello');
        });

        $extra = $this->singleEvent()['extra'] ?? [];
        $this->assertSame('kept', $extra['from_scope'] ?? null);
        $this->assertSame(['username' => 'alice'], $extra['decodedParams'] ?? null);
    }

    public function testRequestWithoutAResolvedActionIsStillReported(): void
    {
        // E.g. a POST that fails in bootstrap or beforeRequest, in an app without an error action.
        $app = $this->createWebApp([], $this->formPost(['username' => 'alice']));
        $app->getRequest()->setRawBody('username=alice');
        $this->assertNull(Yii::$app->requestedAction);

        $app->get('sentry')->getHub()->captureMessage('hello');

        $this->assertSame('POST', $this->singleEvent()['request']['method'] ?? null);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: mixed}>
     */
    public static function nestedBodies(): array
    {
        return [
            'parent field missing' => [['password' => 'x'], 'password=x', null],
            'parent field is a string' => [['profile' => 'abc'], 'profile=abc', 'abc'],
            'parent field is an array' => [
                ['profile' => ['phone' => '555-0100', 'city' => 'Paris']],
                'profile%5Bphone%5D=555-0100&profile%5Bcity%5D=Paris',
                ['phone' => '[Filtered PII]', 'city' => 'Paris'],
            ],
        ];
    }

    /**
     * Clients control the body, so no body shape may make the integration drop the event.
     *
     * @dataProvider nestedBodies
     *
     * @param array<string, mixed> $post
     * @param mixed $expectedProfile
     */
    public function testNestedPiiBodyFieldsNeverDropTheEvent(array $post, string $rawBody, $expectedProfile): void
    {
        $integration = new Integration();
        $integration->piiBodyFields = ['site/post-log' => ['profile' => ['phone']]];
        $app = $this->createWebApp(['integrations' => [$integration]], $this->formPost($post));
        $app->getRequest()->setRawBody($rawBody);
        $this->runningAction($app, 'post-log');

        $app->get('sentry')->getHub()->captureMessage('hello');

        $decoded = $this->singleEvent()['extra']['decodedParams'] ?? [];
        if ($expectedProfile === null) {
            $this->assertArrayNotHasKey('profile', $decoded, 'Filtering must not add fields to the body.');
        } else {
            $this->assertSame($expectedProfile, $decoded['profile'] ?? null);
        }
    }

    public function testSubclassesOfTheIntegrationAreApplied(): void
    {
        $app = $this->createWebApp(['integrations' => [new SubIntegration()]]);

        $app->get('sentry')->getHub()->captureMessage('hello');

        $this->assertSame('GET', $this->singleEvent()['request']['method'] ?? null);
    }

    public function testEachClientUsesItsOwnIntegrationSettings(): void
    {
        $first = new Integration();
        $first->piiReplaceText = '[FIRST]';
        $this->createWebApp(['integrations' => [$first]], ['HTTP_AUTHORIZATION' => 'Bearer one'])
            ->get('sentry')->getHub()->captureMessage('one');

        $second = new Integration();
        $second->piiReplaceText = '[SECOND]';
        $this->createWebApp(['integrations' => [$second]], ['HTTP_AUTHORIZATION' => 'Bearer two'])
            ->get('sentry')->getHub()->captureMessage('two');

        $headers = array_change_key_case($this->singleEvent()['request']['headers'] ?? [], CASE_LOWER);
        $this->assertSame('[SECOND]', $headers['authorization'] ?? null);
    }

    public function testAFailureWhileReadingRequestDataDoesNotLoseTheEvent(): void
    {
        $app = $this->createWebApp(
            [],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/site/post-log', 'CONTENT_TYPE' => 'application/json'],
            ['request' => ['parsers' => ['application/json' => JsonParser::class]]]
        );
        $app->getRequest()->setRawBody('{"invalid json');
        $this->runningAction($app, 'post-log');

        $app->get('sentry')->getHub()->captureMessage('hello');

        $this->assertSame('hello', $this->singleEvent()['message'] ?? null);
        $warnings = array_filter(Yii::getLogger()->messages, static function (array $message): bool {
            return $message[1] === Logger::LEVEL_WARNING && strpos($message[2], 'devnullius\yii2\sentry') === 0;
        });
        $this->assertNotEmpty($warnings, 'The failure should be logged as a warning in the package category.');
    }

    private function runningAction(Application $app, string $actionId): void
    {
        $app->requestedAction = new Action($actionId, new Controller('site', $app));
    }
}
