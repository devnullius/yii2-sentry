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

    public function testPostRequestsKeepTheLogContextInExtras(): void
    {
        $response = $this->request('default', 'POST', '/site/post-log', self::FORM, 'username=alice');

        $extra = $this->singleEvent($response)['extra'] ?? [];
        foreach (['logs', 'traces', 'real_body', 'real_time', 'decodedParams'] as $key) {
            $this->assertArrayHasKey($key, $extra, "extra.$key is missing");
        }
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

        $logs = implode("\n", (array)($this->singleEvent($response)['extra']['logs'] ?? []));
        $this->assertStringContainsString('minor issue before the crash', $logs);
        $this->assertStringNotContainsString($sessionId, $logs, 'A session ID in Sentry allows session hijacking.');
        $this->assertStringNotContainsString('127.0.0.1', $logs);
        $this->assertStringNotContainsString('[42]', $logs);
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
        $response = $this->request('api_pii', 'POST', '/site/post-log', self::FORM, 'username=alice&password=hunter2');

        $this->assertSame(500, $response['status']);
        $this->assertStringNotContainsString('hunter2', (string)json_encode($this->singleEvent($response)));
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
