<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Web;

use devnullius\yii2\sentry\tests\Support\WebServerTestCase;

/**
 * End-to-end behavior of the README's recommended setup, through real HTTP requests. Scenarios are defined in
 * tests/Fixtures/WebApp/config.php.
 */
final class WebReportingTest extends WebServerTestCase
{
    public function testBootsWithDeprecationsReported(): void
    {
        // PHP's built-in default (no php.ini, e.g. official Docker images) reports deprecations, and Yii turns
        // them into exceptions: a deprecation raised while Sentry boots would fail every request.
        $response = $this->request('e_all', 'GET', '/site/index');

        $this->assertSame(200, $response['status'], $response['body']);
    }

    public function testPostRequestsIncludeTheParsedBody(): void
    {
        $response = $this->request('default', 'POST', '/site/post-log', self::FORM, 'username=alice');

        $this->assertSame(['username' => 'alice'], $this->singleEvent($response)['request']['data'] ?? null);
    }

    public function testEachLoggedErrorIsItsOwnEvent(): void
    {
        $response = $this->request('default', 'GET', '/site/log-two');

        $this->assertSame(['first distinct problem', 'second distinct problem'], array_column($response['events'], 'message'));
    }

    public function testAPhpWarningIsReportedOnce(): void
    {
        $response = $this->request('default', 'GET', '/site/warning');

        $exceptions = $this->singleEvent($response)['exception']['values'] ?? [];
        $this->assertSame('yii\base\ErrorException', end($exceptions)['type'] ?? null);
    }

    public function testAFatalErrorIsReportedOnce(): void
    {
        $response = $this->request('default', 'GET', '/site/fatal');

        $exceptions = $this->singleEvent($response)['exception']['values'] ?? [];
        $this->assertStringContainsString('Allowed memory size', end($exceptions)['value'] ?? '');
    }

    public function testAnUncaughtExceptionIsMarkedUnhandled(): void
    {
        $response = $this->request('default', 'GET', '/site/throw');

        $exceptions = $this->singleEvent($response)['exception']['values'] ?? [];
        $this->assertFalse(end($exceptions)['mechanism']['handled'] ?? null);
    }

    public function testWithSendDefaultPiiAUserRestoredFromTheSessionIsAttached(): void
    {
        $login = $this->request('pii_on', 'GET', '/site/login');
        $this->assertNotNull($login['sessionCookie']);

        // Identity restored from the session: Yii does not trigger its login event on this request.
        $response = $this->request('pii_on', 'GET', '/site/warn-then-throw', ['Cookie: ' . $login['sessionCookie']]);

        $crash = $response['events'][count($response['events']) - 1] ?? [];
        $this->assertSame(42, $crash['user']['id'] ?? null);
    }

    public function testExceptionsAreTaggedWithTheRouteThatFailed(): void
    {
        // In production the error action runs before logs are flushed; it must not take over the route tag.
        $response = $this->request('default', 'GET', '/site/throw');

        $this->assertSame(500, $response['status']);
        $this->assertSame('site/throw', $this->singleEvent($response)['tags']['route'] ?? null);
    }

    public function testNestedActionsKeepTheRequestedRoute(): void
    {
        $response = $this->request('default', 'GET', '/site/outer');

        $this->assertSame('site/outer', $this->singleEvent($response)['tags']['route'] ?? null);
    }

    public function testLogLinesDoNotLeakTheSessionIdClientIpOrUserId(): void
    {
        $login = $this->request('default', 'GET', '/site/login');
        $this->assertNotNull($login['sessionCookie']);
        $sessionId = substr((string)$login['sessionCookie'], strlen('PHPSESSID='));

        $response = $this->request('default', 'GET', '/site/warn-then-throw', ['Cookie: ' . $login['sessionCookie']]);

        // The warning is an event of its own and a breadcrumb of the crash that follows it.
        $this->assertCount(2, $response['events']);
        $crash = $response['events'][1];
        $breadcrumbs = (string)json_encode($crash['breadcrumbs']['values'] ?? []);
        $this->assertStringContainsString('minor issue before the crash', $breadcrumbs);
        $this->assertStringNotContainsString('127.0.0.1', $breadcrumbs);
        $this->assertStringNotContainsString('[42]', $breadcrumbs);
        $this->assertStringNotContainsString($sessionId, (string)json_encode($response['events']), 'A session ID in Sentry allows session hijacking.');
    }

    public function testSendDefaultPiiWithTracingKeepsServingAndReporting(): void
    {
        $response = $this->request('pii_on_tracing', 'GET', '/site/log-error');

        $this->assertSame(200, $response['status'], $response['body']);
        $this->singleEvent($response);
        $this->assertNotEmpty($response['transactions']);
    }

    public function testSendDefaultPiiUsesTheRealClientIpAndIgnoresAForgedHeader(): void
    {
        $response = $this->request('pii_on', 'GET', '/site/log-error', ['REMOTE_ADDR: 6.6.6.6', 'Cookie: theme=dark']);

        $event = $this->singleEvent($response);
        $this->assertSame('127.0.0.1', $event['user']['ip_address'] ?? null);
        $this->assertSame(['theme' => 'dark'], $event['request']['cookies'] ?? null);
    }

    public function testFailureBeforeRoutingIsReportedForRequestsWithABody(): void
    {
        $response = $this->request('api', 'POST', '/site/post-log', self::FORM, 'username=alice');

        $this->assertSame(500, $response['status']);
        $exceptions = $this->singleEvent($response)['exception']['values'] ?? [];
        $this->assertSame('database unavailable', end($exceptions)['value'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedNestedBodies(): array
    {
        return [
            'parent field missing' => ['password=hunter2'],
            'parent field sent as a string' => ['profile=just-a-string&password=hunter2'],
        ];
    }

    /**
     * @dataProvider malformedNestedBodies
     */
    public function testClientsCannotSuppressReportingWithAMalformedBody(string $body): void
    {
        $response = $this->request('pii_nested', 'POST', '/site/post-log', self::FORM, $body);

        $this->singleEvent($response);
    }

    public function testReusesASentryClientSetUpBeforeYii(): void
    {
        $response = $this->request('existing_hub', 'GET', '/site/throw', ['Authorization: Bearer secret']);

        // Reported once: Yii's error handler replaces the SDK's exception handler when the app starts.
        $event = $this->singleEvent($response);
        $exceptions = $event['exception']['values'] ?? [];
        $this->assertSame('boom from site/throw', end($exceptions)['value'] ?? null);
        $this->assertSame('site/throw', $event['tags']['route'] ?? null);
        // This package's request data is applied (the SDK's own filter would write "[Filtered]").
        $headers = array_change_key_case($event['request']['headers'] ?? [], CASE_LOWER);
        $this->assertSame('[Filtered PII]', $headers['authorization'] ?? null);
    }

    public function testABodyThatCannotBeParsedIsNotSentUnfiltered(): void
    {
        // piiBodyFields covers "password", but invalid JSON can't be filtered, and the SDK would add the raw body.
        $response = $this->request('json_api', 'POST', '/site/post-log', ['Content-Type: application/json'], '{"password": "hunter2"');

        $this->assertStringNotContainsString('hunter2', (string)json_encode($this->singleEvent($response)));
    }

    public function testTheBodyIsLeftOutWhenNoRouteRuleCanBeApplied(): void
    {
        // The request fails before routing, so the piiBodyFields rules for site/post-log can't be matched.
        $response = $this->request('api_pii', 'POST', '/site/post-log', self::FORM, 'username=alice&nickname=annie');

        $this->assertSame(500, $response['status']);
        $this->assertStringNotContainsString('annie', (string)json_encode($this->singleEvent($response)));
    }

    public function testTheSdkDataCollectionPolicyIsHonored(): void
    {
        $response = $this->request('data_collection', 'POST', '/site/post-log', self::FORM, 'username=alice&password=hunter2');

        $this->assertStringNotContainsString('hunter2', (string)json_encode($this->singleEvent($response)));
    }

    public function testTheDataCollectionPolicyOfAReusedClientIsHonored(): void
    {
        $response = $this->request('existing_hub_data_collection', 'POST', '/site/post-log', self::FORM, 'username=alice&password=hunter2');

        $this->assertStringNotContainsString('hunter2', (string)json_encode($this->singleEvent($response)));
    }

    public function testAReusedClientGetsTheVisitorIpBehindATrustedProxy(): void
    {
        $response = $this->request('existing_hub_pii', 'GET', '/site/log-error', ['X-Forwarded-For: 198.51.100.23']);

        $this->assertSame('198.51.100.23', $this->singleEvent($response)['user']['ip_address'] ?? null);
    }

    public function testALoginFormSendsNeitherThePasswordNorTheEmail(): void
    {
        $response = $this->request('default', 'POST', '/site/post-log', self::FORM, http_build_query([
            'LoginForm' => ['email' => 'ann@example.com', 'password' => 'hunter2', 'rememberMe' => '1'],
        ]));

        $event = (string)json_encode($this->singleEvent($response));
        $this->assertStringNotContainsString('hunter2', $event);
        $this->assertStringNotContainsString('ann@example.com', $event);
        $this->assertStringContainsString('rememberMe', $event, 'Other fields are still sent.');
    }

    public function testWithSendDefaultPiiTheSessionCookieIsStillScrubbed(): void
    {
        $login = $this->request('pii_on', 'GET', '/site/login');
        $this->assertNotNull($login['sessionCookie']);
        $sessionId = substr((string)$login['sessionCookie'], strlen('PHPSESSID='));

        $response = $this->request('pii_on', 'GET', '/site/log-error', ['Cookie: theme=dark; ' . $login['sessionCookie']]);

        $event = $this->singleEvent($response);
        $this->assertStringNotContainsString($sessionId, (string)json_encode($event), 'A session ID in Sentry allows session hijacking.');
        $this->assertSame('dark', $event['request']['cookies']['theme'] ?? null, 'Other cookies are still sent.');
    }

    public function testAMultipartFormIsScrubbedToo(): void
    {
        // PHP reads a multipart body into $_POST itself. Up to 4.1, the SDK then added the fields unfiltered.
        $boundary = 'test-boundary';
        $body = '';
        foreach (['LoginForm[email]' => 'ann@example.com', 'LoginForm[password]' => 'hunter2', 'LoginForm[rememberMe]' => '1'] as $name => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        $body .= "--$boundary--\r\n";

        $response = $this->request('default', 'POST', '/site/post-log', ["Content-Type: multipart/form-data; boundary=$boundary"], $body);

        $this->assertSame(
            ['LoginForm' => ['email' => '[Filtered PII]', 'password' => '[Filtered PII]', 'rememberMe' => '1']],
            $this->singleEvent($response)['request']['data'] ?? null
        );
    }

    public function testProxyHeadersAreFilteredByDefault(): void
    {
        $response = $this->request('trusted_proxy', 'POST', '/site/post-log', array_merge(self::FORM, [
            'X-Forwarded-For: 198.51.100.23',
            'X-Real-IP: 198.51.100.23',
            'Proxy-Authorization: Basic c2VjcmV0',
        ]), 'username=alice');

        $headers = json_encode($this->singleEvent($response)['request']['headers'] ?? []);
        $this->assertStringNotContainsString('198.51.100.23', (string)$headers, 'The visitor IP is personal data.');
        $this->assertStringNotContainsString('c2VjcmV0', (string)$headers);
    }
}
