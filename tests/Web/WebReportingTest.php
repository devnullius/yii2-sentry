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

    public function testAMultipartFormIsFilteredToo(): void
    {
        // PHP reads a multipart body into $_POST itself. Up to 4.1.0, the SDK then added the fields unfiltered.
        [$contentType, $body] = self::multipartForm(['profile[phone]' => '555-0100', 'profile[city]' => 'Paris']);

        $response = $this->request('pii_nested', 'POST', '/site/post-log', [$contentType], $body);

        $event = $this->singleEvent($response);
        $this->assertStringNotContainsString('555-0100', (string)json_encode($event));
        $this->assertSame(['phone' => '[Filtered PII]', 'city' => 'Paris'], $event['extra']['decodedParams']['profile'] ?? null);
    }

    public function testAMultipartBodyIsLeftOutWhenNoRouteRuleCanBeApplied(): void
    {
        [$contentType, $body] = self::multipartForm(['username' => 'alice', 'password' => 'hunter2']);

        $response = $this->request('api_pii', 'POST', '/site/post-log', [$contentType], $body);

        $this->assertSame(500, $response['status']);
        $this->assertStringNotContainsString('hunter2', (string)json_encode($this->singleEvent($response)));
    }

    public function testTheBodyOfARequestWhoseMethodIsNotListedIsNotSent(): void
    {
        // httpMethodsWithRequestBody lists POST, PUT and PATCH: the SDK's RequestIntegration must not send the body of
        // another method instead, unfiltered.
        $response = $this->request('default', 'DELETE', '/site/post-log', self::FORM, 'username=alice&password=hunter2');

        $event = $this->singleEvent($response);
        $this->assertStringNotContainsString('hunter2', (string)json_encode($event));
        $this->assertSame('Not available: DELETE is not in httpMethodsWithRequestBody.', $event['request']['data'] ?? null);
    }

    public function testTheBodyOfAMethodLeftOutOfHttpMethodsWithRequestBodyIsNotSent(): void
    {
        $response = $this->request('post_bodies_only', 'PUT', '/site/post-log', ['Content-Type: application/json'], '{"username":"alice","password":"hunter2"}');

        $this->assertStringNotContainsString('hunter2', (string)json_encode($this->singleEvent($response)));
    }

    public function testAClientCannotGetPiiBodyFieldsSkippedByOverridingTheMethod(): void
    {
        // Yii takes the method from the X-Http-Method-Override header, and json_api filters "password" on this route.
        $response = $this->request('json_api', 'POST', '/site/post-log', ['Content-Type: application/json', 'X-Http-Method-Override: DELETE'], '{"username":"alice","password":"hunter2"}');

        $this->assertStringNotContainsString('hunter2', (string)json_encode($this->singleEvent($response)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function methodsOfRequestsWithoutABody(): array
    {
        return ['GET' => ['GET'], 'DELETE' => ['DELETE']];
    }

    /**
     * @dataProvider methodsOfRequestsWithoutABody
     */
    public function testARequestWithoutABodyGetsNoBodyData(string $method): void
    {
        $response = $this->request('default', $method, '/site/log-error');

        $this->assertArrayNotHasKey('data', $this->singleEvent($response)['request'] ?? []);
    }

    public function testTheSdkDataCollectionPolicyIsHonored(): void
    {
        $response = $this->request('data_collection', 'POST', '/site/post-log', self::FORM, 'username=alice&password=hunter2');

        $this->assertStringNotContainsString('hunter2', (string)json_encode($this->singleEvent($response)));
    }

    public function testWithTheDataCollectionOptionYiisSessionCookieIsLeftOutUnderTheNameTheApplicationGivesIt(): void
    {
        $response = $this->request('data_collection_cookies', 'GET', '/site/log-error', ['Cookie: advanced-frontend=s3ss10n-1d; theme=dark; lang=hy']);

        $event = $this->singleEvent($response);
        $sent = (string)json_encode($event);
        $this->assertStringNotContainsString('s3ss10n-1d', $sent, 'A session ID in Sentry allows session hijacking.');
        // The application's own terms are kept, and the other cookies collected.
        $this->assertStringNotContainsString('dark', $sent);
        $this->assertStringContainsString('hy', (string)json_encode($event['request']['cookies'] ?? null));
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

    /**
     * @param array<string, string> $fields
     *
     * @return array{0: string, 1: string} the Content-Type header and the body
     */
    private static function multipartForm(array $fields): array
    {
        $boundary = 'test-boundary';
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }

        return ["Content-Type: multipart/form-data; boundary=$boundary", $body . "--$boundary--\r\n"];
    }
}
