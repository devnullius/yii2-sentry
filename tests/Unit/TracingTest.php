<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\LogTarget;
use devnullius\yii2\sentry\tests\Support\FakeHttpTransport;
use devnullius\yii2\sentry\tests\Support\TestCase;
use RuntimeException;
use Yii;
use yii\base\ActionEvent;
use yii\base\Application as BaseApplication;
use yii\base\Controller;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\httpclient\Client;
use yii\httpclient\Exception as HttpClientException;
use yii\log\Logger;
use yii\web\Application;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Tracing: one transaction per request or console command, spans for what it does, and trace linking.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TracingTest extends TestCase
{
    private const REQUEST_START = 1700000000.25;
    private const INCOMING_TRACE_ID = '771a43a4192642f0b136d5159a501700';
    private const INCOMING_HEADERS = [
        'HTTP_SENTRY_TRACE' => '771a43a4192642f0b136d5159a501700-1234567890abcdef-1',
        'HTTP_BAGGAGE' => 'sentry-trace_id=771a43a4192642f0b136d5159a501700,sentry-public_key=public,sentry-sample_rate=1,sentry-sampled=true',
    ];

    // --- Requests -----------------------------------------------------------------------------------------------

    public function testARequestIsOneTransactionNamedByItsRoute(): void
    {
        $app = $this->tracedWebApp();

        $app->runAction('site/index');
        $transaction = $this->finishRequest($app, 200);

        $this->assertSame('site/index', $transaction['transaction'] ?? null);
        $this->assertSame('route', $transaction['transaction_info']['source'] ?? null);
        $this->assertSame('http.server', $transaction['contexts']['trace']['op'] ?? null);
        $this->assertSame('ok', $transaction['contexts']['trace']['status'] ?? null);
        $this->assertSame(200, $transaction['contexts']['response']['status_code'] ?? null);
        // Timed from when PHP received the request, so that bootstrap and routing are included.
        $this->assertSame(self::REQUEST_START, $transaction['start_timestamp'] ?? null);
    }

    public function testARequestWithoutAMatchingRouteKeepsItsPathAsName(): void
    {
        $app = $this->tracedWebApp([], ['REQUEST_URI' => '/no/such/page?id=7']);
        // Routing failed, so the error page is the first action that runs.
        $app->getErrorHandler()->exception = new NotFoundHttpException('Page not found.');
        $app->runAction('site/error');

        $transaction = $this->finishRequest($app, 404);

        $this->assertSame('/no/such/page', $transaction['transaction'] ?? null);
        $this->assertSame('url', $transaction['transaction_info']['source'] ?? null);
        $this->assertSame('not_found', $transaction['contexts']['trace']['status'] ?? null);
    }

    public function testAFailedRequestKeepsTheRouteThatFailed(): void
    {
        $app = $this->tracedWebApp();
        try {
            $app->runAction('site/throw');
        } catch (RuntimeException $exception) {
            $app->getErrorHandler()->exception = $exception;
        }
        $app->runAction('site/error');

        $transaction = $this->finishRequest($app, 500);

        $this->assertSame('site/throw', $transaction['transaction'] ?? null);
        $this->assertSame('internal_error', $transaction['contexts']['trace']['status'] ?? null);
        // The failed action's span is closed as failed, and the error page is not nested in it.
        $actions = $this->spans($transaction, 'yii.action');
        $this->assertSame(['site/throw', 'site/error'], array_column($actions, 'description'));
        $this->assertSame('internal_error', $actions[0]['status'] ?? null);
        $this->assertSame($transaction['contexts']['trace']['span_id'] ?? null, $actions[1]['parent_span_id'] ?? null);
    }

    public function testBootstrapActionsAndViewsAreSpans(): void
    {
        $app = $this->tracedWebApp();

        $app->trigger(BaseApplication::EVENT_BEFORE_REQUEST);
        $app->runAction('site/render');
        $transaction = $this->finishRequest($app, 200);

        $bootstrap = $this->spans($transaction, 'app.bootstrap')[0] ?? [];
        $this->assertSame(self::REQUEST_START, $bootstrap['start_timestamp'] ?? null);
        $action = $this->spans($transaction, 'yii.action')[0] ?? [];
        $this->assertSame('site/render', $action['description'] ?? null);
        $view = $this->spans($transaction, 'view.render')[0] ?? [];
        $this->assertSame('tests/Fixtures/WebApp/views/site/hello.php', $view['description'] ?? null, 'Shown relative to the project root.');
        $this->assertSame($action['span_id'] ?? null, $view['parent_span_id'] ?? null, 'The view is rendered inside the action.');
    }

    public function testNestedActionsAreNestedSpans(): void
    {
        $app = $this->tracedWebApp();

        $app->runAction('site/outer');
        $transaction = $this->finishRequest($app, 200);

        $actions = $this->spans($transaction, 'yii.action');
        $this->assertSame(['site/outer', 'site/inner'], array_column($actions, 'description'));
        $this->assertSame($actions[0]['span_id'] ?? null, $actions[1]['parent_span_id'] ?? null);
        $this->assertSame('site/outer', $transaction['transaction'] ?? null);
    }

    public function testErrorsAreLinkedToTheRequestsTrace(): void
    {
        $app = $this->tracedWebApp();
        $app->runAction('site/index');

        $app->get('sentry')->getHub()->captureMessage('a problem');
        $transaction = $this->finishRequest($app, 200);

        $event = $this->singleEvent();
        $this->assertSame($transaction['contexts']['trace']['trace_id'] ?? null, $event['contexts']['trace']['trace_id'] ?? null);
        $this->assertSame('site/index', $event['transaction'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function partsThatCanBeTurnedOff(): array
    {
        return [
            'actions' => ['actions', 'yii.action'],
            'views' => ['views', 'view.render'],
        ];
    }

    /**
     * @dataProvider partsThatCanBeTurnedOff
     */
    public function testSpansCanBeTurnedOff(string $setting, string $op): void
    {
        $app = $this->tracedWebApp(['tracing' => [$setting => false]]);

        $app->runAction('site/render');
        $transaction = $this->finishRequest($app, 200);

        $this->assertSame([], $this->spans($transaction, $op));
    }

    public function testRequestTracingCanBeTurnedOff(): void
    {
        $app = $this->tracedWebApp(['tracing' => ['requests' => false]]);

        $app->runAction('site/index');
        $app->getResponse()->trigger(Response::EVENT_AFTER_SEND);

        $this->assertSame([], $this->transactions());
    }

    public function testNothingIsTracedWithoutTracesSampleRate(): void
    {
        $app = $this->createWebApp();

        $app->runAction('site/index');
        $app->getResponse()->trigger(Response::EVENT_AFTER_SEND);

        $this->assertSame([], $this->transactions());
    }

    public function testAFailureWhileTracingDoesNotBreakTheApplication(): void
    {
        $app = $this->tracedWebApp();

        // A broken event, standing in for anything that goes wrong while tracing.
        Event::trigger(Controller::class, Controller::EVENT_BEFORE_ACTION, new ActionEvent(null));
        $app->runAction('site/index');
        $this->finishRequest($app, 200);

        $warnings = array_filter(Yii::getLogger()->messages, static function (array $message): bool {
            return $message[1] === Logger::LEVEL_WARNING && strpos((string)$message[2], 'devnullius\\yii2\\sentry\\') === 0;
        });
        $this->assertNotEmpty($warnings, 'The failure is logged as a warning.');
    }

    public function testUnknownTracingSettingsAreRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Unknown Sentry tracing setting "view"');

        $this->createWebApp(['tracing' => ['view' => false]]);
    }

    // --- Console commands ---------------------------------------------------------------------------------------

    public function testAConsoleCommandIsOneTransaction(): void
    {
        $app = $this->createConsoleApp(['sentrySettings' => ['traces_sample_rate' => 1.0]]);
        $_SERVER['argv'] = ['yii', 'hello/index'];

        $app->run();

        $transaction = $this->transactions()[0] ?? [];
        $this->assertSame('hello/index', $transaction['transaction'] ?? null);
        $this->assertSame('task', $transaction['transaction_info']['source'] ?? null);
        $this->assertSame('console.command', $transaction['contexts']['trace']['op'] ?? null);
        $this->assertSame('ok', $transaction['contexts']['trace']['status'] ?? null);
    }

    public function testAFailedConsoleCommandIsMarkedAsFailed(): void
    {
        $app = $this->createConsoleApp(['sentrySettings' => ['traces_sample_rate' => 1.0]]);
        $_SERVER['argv'] = ['yii', 'hello/fail'];

        $app->run();

        $this->assertSame('internal_error', $this->transactions()[0]['contexts']['trace']['status'] ?? null);
    }

    public function testAConsoleCommandIsMarkedByTheExitCodeOfTheResponseItReturns(): void
    {
        $app = $this->createConsoleApp(['sentrySettings' => ['traces_sample_rate' => 1.0]]);
        $_SERVER['argv'] = ['yii', 'hello/respond'];

        $app->run();

        $this->assertSame('internal_error', $this->transactions()[0]['contexts']['trace']['status'] ?? null);
    }

    public function testCommandTracingCanBeTurnedOff(): void
    {
        $app = $this->createConsoleApp(['sentrySettings' => ['traces_sample_rate' => 1.0], 'tracing' => ['commands' => false]]);
        $_SERVER['argv'] = ['yii', 'hello/index'];

        $app->run();

        $this->assertSame([], $this->transactions());
    }

    // --- Outgoing HTTP calls ------------------------------------------------------------------------------------

    public function testOutgoingHttpCallsAreSpansThatTheCalledServiceContinues(): void
    {
        $app = $this->tracedWebApp();
        $transport = new FakeHttpTransport();

        (new Client(['transport' => $transport]))->post('https://api.example.com/v1/charges?api_key=k1&page=2', ['amount' => '100'])->send();
        $transaction = $this->finishRequest($app, 200);

        $span = $this->spans($transaction, 'http.client')[0] ?? [];
        $this->assertSame('POST https://api.example.com/v1/charges', $span['description'] ?? null);
        $this->assertSame('api_key=[Filtered PII]&page=2', $span['data']['http.query'] ?? null);
        $this->assertSame(201, $span['data']['http.response.status_code'] ?? null);
        $this->assertSame('ok', $span['status'] ?? null);
        $headers = $transport->sent[0]->getHeaders();
        $traceId = $transaction['contexts']['trace']['trace_id'] ?? '';
        $this->assertSame("$traceId-" . ($span['span_id'] ?? '') . '-1', $headers->get('sentry-trace'));
        $this->assertStringContainsString("sentry-trace_id=$traceId", (string)$headers->get('baggage'));
    }

    public function testOutgoingHttpCallsAreBreadcrumbsEvenWithoutTracing(): void
    {
        $this->createWebApp([], [], ['log' => ['targets' => ['sentry' => ['class' => LogTarget::class]]]])->getLog();
        $transport = new FakeHttpTransport();
        $transport->statusCode = 503;

        (new Client(['transport' => $transport]))->get('https://api.example.com/v1/status?token=t1')->send();
        Yii::error('a later problem', 'app');
        Yii::getLogger()->flush(true);

        $breadcrumbs = $this->singleEvent()['breadcrumbs']['values'] ?? [];
        $this->assertSame([[
            'type' => 'http',
            'category' => 'http',
            'level' => 'error',
            'data' => [
                'url' => 'https://api.example.com/v1/status',
                'http.request.method' => 'GET',
                'http.query' => 'token=[Filtered PII]',
                'http.response.status_code' => 503,
            ],
        ]], array_map(static function (array $breadcrumb): array {
            unset($breadcrumb['timestamp']);

            return $breadcrumb;
        }, array_values(array_filter($breadcrumbs, static function (array $breadcrumb): bool {
            return strpos((string)($breadcrumb['category'] ?? ''), 'http') !== false;
        }))), "One breadcrumb per call: Yii's own log line for it is not added too.");
        $this->assertNotNull($transport->sent[0]->getHeaders()->get('sentry-trace'), 'The trace is passed on without tracing too.');
    }

    public function testACallThatGotNoResponseIsABreadcrumbToo(): void
    {
        $app = $this->tracedWebApp([], [], ['log' => ['targets' => ['sentry' => ['class' => LogTarget::class]]]]);
        $app->getLog();
        $transport = new FakeHttpTransport();
        $transport->failure = 'Curl error: #7 - Failed to connect to api.example.com';

        try {
            (new Client(['transport' => $transport]))->post('https://api.example.com/v1/charges')->send();
        } catch (HttpClientException $exception) {
            Yii::error($exception, get_class($exception));
        }
        Yii::getLogger()->flush(true);
        $transaction = $this->finishRequest($app, 500);

        $breadcrumbs = array_values(array_filter($this->singleEvent()['breadcrumbs']['values'] ?? [], static function (array $breadcrumb): bool {
            return strpos((string)($breadcrumb['category'] ?? ''), 'http') !== false;
        }));
        $this->assertCount(1, $breadcrumbs, "Yii's own log line for the call is not added too.");
        $this->assertSame(['error', 'No response'], [$breadcrumbs[0]['level'] ?? null, $breadcrumbs[0]['message'] ?? null]);
        $this->assertSame('https://api.example.com/v1/charges', $breadcrumbs[0]['data']['url'] ?? null);
        $this->assertSame('internal_error', $this->spans($transaction, 'http.client')[0]['status'] ?? null);
    }

    public function testTraceHeadersOnlyGoToTheTracePropagationTargets(): void
    {
        $this->tracedWebApp(['sentrySettings' => ['trace_propagation_targets' => ['internal.example.com']]]);
        $transport = new FakeHttpTransport();
        $client = new Client(['transport' => $transport]);

        $client->get('https://api.example.com/v1/status')->send();
        $client->get('https://internal.example.com/v1/status')->send();

        $this->assertNull($transport->sent[0]->getHeaders()->get('sentry-trace'));
        $this->assertNotNull($transport->sent[1]->getHeaders()->get('sentry-trace'));
    }

    public function testHttpClientTracingCanBeTurnedOff(): void
    {
        $app = $this->tracedWebApp(['tracing' => ['httpClient' => false]]);
        $transport = new FakeHttpTransport();

        (new Client(['transport' => $transport]))->get('https://api.example.com/v1/status')->send();
        $transaction = $this->finishRequest($app, 200);

        $this->assertSame([], $this->spans($transaction, 'http.client'));
        $this->assertNull($transport->sent[0]->getHeaders()->get('sentry-trace'));
    }

    public function testCredentialsInTheUrlOfAnOutgoingCallAreScrubbed(): void
    {
        $app = $this->tracedWebApp([], [], ['log' => ['targets' => ['sentry' => ['class' => LogTarget::class]]]]);
        $app->getLog();
        $transport = new FakeHttpTransport();

        (new Client(['transport' => $transport]))->get('https://user:hunter2@api.example.com/v1/status?token=t1')->send();
        Yii::error('a later problem', 'app');
        Yii::getLogger()->flush(true);
        $transaction = $this->finishRequest($app, 200);

        $url = 'https://[Filtered PII]@api.example.com/v1/status';
        $span = $this->spans($transaction, 'http.client')[0] ?? [];
        $this->assertSame(["GET $url", $url], [$span['description'] ?? null, $span['data']['url'] ?? null]);
        $breadcrumbs = array_filter($this->singleEvent()['breadcrumbs']['values'] ?? [], static function (array $breadcrumb): bool {
            return ($breadcrumb['category'] ?? null) === 'http';
        });
        $this->assertSame([$url], array_column(array_column($breadcrumbs, 'data'), 'url'));
        $this->assertStringNotContainsString('hunter2', (string)json_encode([$this->transport->events(), $transaction]));
        $this->assertSame(
            'https://user:hunter2@api.example.com/v1/status?token=t1',
            $transport->sent[0]->getFullUrl(),
            'The call is made as it was.'
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function credentialSettings(): array
    {
        return [
            'scrubbed by default' => [[], 'GET https://[Filtered PII]@api.example.com/v1/status'],
            'sent with secrets' => [['privacy' => ['scrubSecrets' => false]], 'GET https://user:hunter2@api.example.com/v1/status'],
            'left out without the privacy integration' => [['integrations' => []], 'GET https://api.example.com/v1/status'],
        ];
    }

    /**
     * @dataProvider credentialSettings
     *
     * @param array<string, mixed> $sentry
     */
    public function testCredentialsInTheUrlOfAnOutgoingCallFollowScrubSecrets(array $sentry, string $description): void
    {
        $app = $this->tracedWebApp($sentry);

        (new Client(['transport' => new FakeHttpTransport()]))->get('https://user:hunter2@api.example.com/v1/status')->send();
        $transaction = $this->finishRequest($app, 200);

        $this->assertSame($description, $this->spans($transaction, 'http.client')[0]['description'] ?? null);
    }

    // --- Database queries ---------------------------------------------------------------------------------------

    public function testSqlQueriesAreSpansWithTheirValuesMasked(): void
    {
        $app = $this->tracedWebApp([], [], ['db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:']]);

        $app->runAction('site/query');
        $transaction = $this->finishRequest($app, 200);

        $queries = $this->spans($transaction, 'db.sql.query');
        $this->assertSame(['SELECT ? AS email'], array_column($queries, 'description'));
        $this->assertSame($this->spans($transaction, 'yii.action')[0]['span_id'] ?? null, $queries[0]['parent_span_id'] ?? null, 'The query ran inside the action.');
        $this->assertGreaterThanOrEqual($queries[0]['start_timestamp'] ?? INF, $queries[0]['timestamp'] ?? 0.0);
        $this->assertStringNotContainsString('ann@example.com', (string)json_encode($transaction));
    }

    public function testQueriesFromBeforeAnEarlierLogFlushAreKept(): void
    {
        $app = $this->tracedWebApp([], [], ['db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:']]);
        // Yii hands logged messages to the log targets every "flushInterval" messages (1000 by default).
        Yii::getLogger()->flushInterval = 2;

        foreach ([1, 2, 3] as $number) {
            $app->getDb()->createCommand("SELECT $number")->queryScalar();
        }
        $transaction = $this->finishRequest($app, 200);

        $this->assertSame(['SELECT ?', 'SELECT ?', 'SELECT ?'], array_column($this->spans($transaction, 'db.sql.query'), 'description'));
    }

    public function testDatabaseTracingCanBeTurnedOff(): void
    {
        $app = $this->tracedWebApp(['tracing' => ['database' => false]], [], ['db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:']]);

        $app->runAction('site/query');
        $transaction = $this->finishRequest($app, 200);

        $this->assertSame([], $this->spans($transaction, 'db.sql.query'));
    }

    public function testSqlValuesInQuerySpansCanBeKept(): void
    {
        $app = $this->tracedWebApp(
            ['tracing' => ['maskSqlValues' => false]],
            [],
            ['db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:']]
        );

        $app->runAction('site/query');
        $transaction = $this->finishRequest($app, 200);

        $this->assertSame(["SELECT 'ann@example.com' AS email"], array_column($this->spans($transaction, 'db.sql.query'), 'description'));
    }

    // --- Trace linking ------------------------------------------------------------------------------------------

    public function testErrorsJoinTheCallersTraceEvenWithoutTracing(): void
    {
        $app = $this->createWebApp([], self::INCOMING_HEADERS);

        $app->get('sentry')->getHub()->captureMessage('a problem');

        $trace = $this->singleEvent()['contexts']['trace'] ?? [];
        $this->assertSame(self::INCOMING_TRACE_ID, $trace['trace_id'] ?? null);
        $this->assertSame('1234567890abcdef', $trace['parent_span_id'] ?? null);
    }

    public function testATracedRequestContinuesTheCallersTrace(): void
    {
        $app = $this->tracedWebApp([], self::INCOMING_HEADERS);

        $app->runAction('site/index');
        $trace = $this->finishRequest($app, 200)['contexts']['trace'] ?? [];

        $this->assertSame(self::INCOMING_TRACE_ID, $trace['trace_id'] ?? null);
        $this->assertSame('1234567890abcdef', $trace['parent_span_id'] ?? null);
    }

    public function testContinuingTheCallersTraceCanBeTurnedOff(): void
    {
        $app = $this->createWebApp(['tracing' => ['continueTraces' => false]], self::INCOMING_HEADERS);

        $app->get('sentry')->getHub()->captureMessage('a problem');

        $this->assertNotSame(self::INCOMING_TRACE_ID, $this->singleEvent()['contexts']['trace']['trace_id'] ?? null);
    }

    public function testTraceMetaTagsLetTheBrowserJoinTheTrace(): void
    {
        $app = $this->createWebApp([], self::INCOMING_HEADERS);

        $tags = $app->get('sentry')->traceMetaTags();

        $this->assertStringContainsString('<meta name="sentry-trace" content="' . self::INCOMING_TRACE_ID . '-', $tags);
        $this->assertStringContainsString('<meta name="baggage" content="sentry-trace_id=' . self::INCOMING_TRACE_ID, $tags);
    }

    // --- Helpers ------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $sentry
     * @param array<string, mixed> $server
     * @param array<string, mixed> $components
     */
    private function tracedWebApp(array $sentry = [], array $server = [], array $components = []): Application
    {
        $sentry['sentrySettings'] = array_merge(['traces_sample_rate' => 1.0], $sentry['sentrySettings'] ?? []);

        return $this->createWebApp($sentry, array_merge(['REQUEST_TIME_FLOAT' => self::REQUEST_START], $server), $components);
    }

}
