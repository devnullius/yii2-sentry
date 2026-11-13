<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\Integration;
use devnullius\yii2\sentry\LogTarget;
use devnullius\yii2\sentry\tests\Support\TestCase;
use PDOException;
use RuntimeException;
use Sentry\DataCollection\KeyValueCollectionBehavior;
use Yii;
use yii\base\Action;
use yii\base\InvalidConfigException;
use yii\db\IntegrityException;
use yii\web\Application;
use yii\web\Controller;

/**
 * What leaves the server: secrets are scrubbed even with send_default_pii; personal data follows send_default_pii.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PersonalDataTest extends TestCase
{
    private const FILTERED = '[Filtered PII]';

    // --- Secrets ------------------------------------------------------------------------------------------------

    public function testSecretsInTheBodyAreScrubbedAtAnyDepth(): void
    {
        $event = $this->postForm(['LoginForm' => ['username' => 'alice', 'password' => 'hunter2'], 'api_key' => 'k1']);

        $this->assertSame(
            ['LoginForm' => ['username' => 'alice', 'password' => self::FILTERED], 'api_key' => self::FILTERED],
            $event['request']['data'] ?? null
        );
    }

    public function testSecretsAreScrubbedEvenWithSendDefaultPii(): void
    {
        $event = $this->postForm(['username' => 'alice', 'password' => 'hunter2'], ['sentrySettings' => ['send_default_pii' => true]]);

        $this->assertSame(['username' => 'alice', 'password' => self::FILTERED], $event['request']['data'] ?? null);
    }

    public function testSecretScrubbingCanBeTurnedOff(): void
    {
        $event = $this->postForm(['password' => 'hunter2'], ['privacy' => ['scrubSecrets' => false]]);

        $this->assertSame(['password' => 'hunter2'], $event['request']['data'] ?? null);
    }

    public function testNamesAreMatchedWithoutOverreaching(): void
    {
        $event = $this->postForm([
            // Long words match anywhere in a name, short ones only as whole words.
            'newPassword' => 'x', 'accessToken' => 'x', 'x_api_key' => 'x', 'pin' => 'x', 'cvv2' => 'x', 'sids' => 'x',
            'monkey' => 'kept', 'keyword' => 'kept', 'author' => 'kept', 'inside' => 'kept', 'shipping' => 'kept',
        ]);

        $this->assertSame([
            'newPassword' => self::FILTERED, 'accessToken' => self::FILTERED, 'x_api_key' => self::FILTERED,
            'pin' => self::FILTERED, 'cvv2' => self::FILTERED, 'sids' => self::FILTERED,
            'monkey' => 'kept', 'keyword' => 'kept', 'author' => 'kept', 'inside' => 'kept', 'shipping' => 'kept',
        ], $event['request']['data'] ?? null);
    }

    public function testSecretHeadersAreScrubbedEvenWithSendDefaultPii(): void
    {
        $event = $this->get(['sentrySettings' => ['send_default_pii' => true]], [
            'HTTP_AUTHORIZATION' => 'Bearer secret',
            'HTTP_X_API_KEY' => 'k1',
            'HTTP_X_CSRF_TOKEN' => 't1',
            'HTTP_COOKIE' => 'PHPSESSID=s1',
            'HTTP_X_REQUEST_ID' => 'r1',
        ]);

        $headers = array_change_key_case($event['request']['headers'] ?? [], CASE_LOWER);
        foreach (['authorization', 'x-api-key', 'x-csrf-token'] as $name) {
            $this->assertSame(self::FILTERED, $headers[$name] ?? null, "The $name header");
        }
        $this->assertArrayNotHasKey('cookie', $headers, 'Cookies are sent apart, scrubbed.');
        $this->assertSame(['r1'], $headers['x-request-id'] ?? null);
    }

    public function testACookieHeaderThatIsKeptIsScrubbed(): void
    {
        $event = $this->get(['privacy' => ['stripHeaders' => []]], ['HTTP_COOKIE' => 'PHPSESSID=s1']);

        $headers = array_change_key_case($event['request']['headers'] ?? [], CASE_LOWER);
        $this->assertSame(self::FILTERED, $headers['cookie'] ?? null);
    }

    public function testSecretCookiesAreFoundByYiisOwnCookieNames(): void
    {
        // The advanced application template names its session cookie "advanced-frontend": no word list would catch it.
        $_COOKIE = ['advanced-frontend' => 's1', '_csrf' => 'c1', '_identity' => 'i1', 'theme' => 'dark'];

        $event = $this->get(['sentrySettings' => ['send_default_pii' => true]], [], [
            'session' => ['name' => 'advanced-frontend'],
        ]);

        $this->assertSame(
            ['advanced-frontend' => self::FILTERED, '_csrf' => self::FILTERED, '_identity' => self::FILTERED, 'theme' => 'dark'],
            $event['request']['cookies'] ?? null
        );
    }

    public function testSecretFunctionArgumentsAreScrubbed(): void
    {
        // PHP's default when no php.ini sets it, as in the official Docker images.
        ini_set('zend.exception_ignore_args', '0');
        $app = $this->createWebApp();

        try {
            // Like Yii's Security::validatePassword(), which throws "Hash is invalid." for a broken hash.
            self::validatePassword('hunter2', 'ann@example.com');
        } catch (RuntimeException $exception) {
            $app->get('sentry')->getHub()->captureException($exception);
        }

        $event = $this->singleEvent();
        $this->assertStringNotContainsString('hunter2', (string)json_encode($event));
        $this->assertStringNotContainsString('ann@example.com', (string)json_encode($event), 'Personal data is hidden without send_default_pii.');
        $frames = $event['exception']['values'][0]['stacktrace']['frames'] ?? [];
        $this->assertContains(['password' => self::FILTERED, 'email' => self::FILTERED], array_column($frames, 'vars'));
    }

    // --- Personal data ------------------------------------------------------------------------------------------

    public function testPersonalFieldsAreHiddenWhileSendDefaultPiiIsOff(): void
    {
        $event = $this->postForm([
            'email' => 'ann@example.com', 'phone' => '555-0100', 'first_name' => 'Ann', 'address' => 'Main St 1',
            'company' => 'ACME',
        ]);

        $this->assertSame([
            'email' => self::FILTERED, 'phone' => self::FILTERED, 'first_name' => self::FILTERED,
            'address' => self::FILTERED, 'company' => 'ACME',
        ], $event['request']['data'] ?? null);
    }

    public function testPersonalFieldsAreSentWithSendDefaultPii(): void
    {
        $event = $this->postForm(['email' => 'ann@example.com', 'password' => 'hunter2'], ['sentrySettings' => ['send_default_pii' => true]]);

        $this->assertSame(['email' => 'ann@example.com', 'password' => self::FILTERED], $event['request']['data'] ?? null);
    }

    public function testPersonalFieldsCanBeShownWhileSendDefaultPiiIsOff(): void
    {
        $event = $this->postForm(['email' => 'ann@example.com'], ['privacy' => ['scrubPersonalData' => false]]);

        $this->assertSame(['email' => 'ann@example.com'], $event['request']['data'] ?? null);
    }

    public function testPersonalFieldsCanBeHiddenEvenWithSendDefaultPii(): void
    {
        $event = $this->postForm(['email' => 'ann@example.com'], [
            'privacy' => ['scrubPersonalData' => true],
            'sentrySettings' => ['send_default_pii' => true],
        ]);

        $this->assertSame(['email' => self::FILTERED], $event['request']['data'] ?? null);
    }

    public function testTheFieldListsCanBeExtended(): void
    {
        $event = $this->postForm(['nickname' => 'annie'], [
            'privacy' => ['personalFields' => array_merge(Integration::PERSONAL_FIELDS, ['nickname'])],
        ]);

        $this->assertSame(['nickname' => self::FILTERED], $event['request']['data'] ?? null);
    }

    public function testRouteSpecificFieldsMatchAtAnyDepth(): void
    {
        $event = $this->postForm(['Profile' => ['nickname' => 'annie', 'bio' => 'hi']], [
            'privacy' => ['piiBodyFields' => ['site/post-log' => ['nickname']]],
        ]);

        $this->assertSame(['Profile' => ['nickname' => self::FILTERED, 'bio' => 'hi']], $event['request']['data'] ?? null);
    }

    public function testRouteSpecificFieldsAreSentWithSendDefaultPii(): void
    {
        $event = $this->postForm(['nickname' => 'annie'], [
            'privacy' => ['piiBodyFields' => ['site/post-log' => ['nickname']]],
            'sentrySettings' => ['send_default_pii' => true],
        ]);

        $this->assertSame(['nickname' => 'annie'], $event['request']['data'] ?? null);
    }

    public function testUnknownPrivacySettingsAreRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Unknown Sentry privacy setting "scrubPersonalDate"');

        $this->createWebApp(['privacy' => ['scrubPersonalDate' => false]]);
    }

    // --- Bodies -------------------------------------------------------------------------------------------------

    public function testTheBodyIsSentOnce(): void
    {
        $event = $this->postForm(['username' => 'alice']);

        $this->assertSame(['username' => 'alice'], $event['request']['data'] ?? null);
        $this->assertArrayNotHasKey('decodedParams', $event['extra'] ?? []);
    }

    public function testMultipartFormsAreScrubbedToo(): void
    {
        // PHP reads a multipart body into $_POST itself and leaves the raw body empty.
        $_POST = ['LoginForm' => ['username' => 'alice', 'password' => 'hunter2']];
        $app = $this->createWebApp([], [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/site/post-log',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        ]);
        $app->getRequest()->setRawBody('');

        $event = $this->captureFromAction($app);

        $this->assertSame(['LoginForm' => ['username' => 'alice', 'password' => self::FILTERED]], $event['request']['data'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function methodsWithJsonBodies(): array
    {
        // Without a parser, Yii turns the JSON body of a PUT into one field named after the whole text.
        return ['POST' => ['POST'], 'PUT' => ['PUT']];
    }

    /**
     * @dataProvider methodsWithJsonBodies
     */
    public function testJsonBodiesAreScrubbedEvenWithoutYiisJsonParser(string $method): void
    {
        $event = $this->sendRaw($method, '{"user":{"name":"Ann","password":"hunter2"}}', 'application/json');

        $this->assertSame(['user' => ['name' => 'Ann', 'password' => self::FILTERED]], $event['request']['data'] ?? null);
    }

    public function testBodiesThatCannotBeReadAsFieldsAreLeftOut(): void
    {
        $event = $this->sendRaw('POST', '<login><password>hunter2</password></login>', 'application/xml');

        $this->assertSame('[not sent: the body is not form data or JSON]', $event['request']['data'] ?? null);
        $this->assertStringNotContainsString('hunter2', (string)json_encode($event));
    }

    public function testBodiesThatCannotBeReadAsFieldsCanBeSentAsText(): void
    {
        $xml = '<order><id>7</id></order>';

        $event = $this->sendRaw('POST', $xml, 'application/xml', ['privacy' => ['sendUnparsedBodies' => true]]);

        $this->assertSame($xml, $event['request']['data'] ?? null);
    }

    public function testBodiesOverTheSdkSizeLimitAreLeftOut(): void
    {
        $event = $this->postForm(['notes' => str_repeat('a', 2000)], ['sentrySettings' => ['max_request_body_size' => 'small']]);

        $this->assertSame('[not sent: larger than max_request_body_size]', $event['request']['data'] ?? null);
    }

    // --- Query string and Referer -------------------------------------------------------------------------------

    public function testSecretsAndPersonalDataInTheQueryStringAreScrubbed(): void
    {
        $event = $this->get([], [
            'REQUEST_URI' => '/site/index?token=abc&email=ann%40example.com&page=2',
            'QUERY_STRING' => 'token=abc&email=ann%40example.com&page=2',
        ]);

        $this->assertSame('token=[Filtered PII]&email=[Filtered PII]&page=2', $event['request']['query_string'] ?? null);
    }

    public function testTheQueryStringOfTheRefererIsScrubbed(): void
    {
        $event = $this->get([], ['HTTP_REFERER' => 'https://example.com/site/reset?token=abc&step=2#form']);

        $headers = array_change_key_case($event['request']['headers'] ?? [], CASE_LOWER);
        $this->assertSame(['https://example.com/site/reset?token=[Filtered PII]&step=2#form'], $headers['referer'] ?? null);
    }

    // --- Database errors ----------------------------------------------------------------------------------------

    public function testValuesInDatabaseErrorsAreMasked(): void
    {
        $driverMessage = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ann@example.com' for key 'email'";
        $exception = new IntegrityException(
            $driverMessage . "\nThe SQL being executed was: INSERT INTO `user` (`email`, `age`) VALUES ('ann@example.com', 42)",
            [],
            '23000',
            new PDOException($driverMessage)
        );

        $values = $this->captureException($exception);

        $this->assertStringNotContainsString('ann@example.com', (string)json_encode($values));
        $this->assertSame([
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry ? for key 'email'",
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry ? for key 'email'\nThe SQL being executed was: INSERT INTO `user` (`email`, `age`) VALUES (?, ?)",
        ], array_column($values, 'value'));
    }

    public function testPostgresStyleDetailsAreMaskedToo(): void
    {
        $exception = new IntegrityException(
            "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"user_email_key\"\n"
            . "DETAIL:  Key (email)=(ann@example.com) already exists.\n"
            . "The SQL being executed was: INSERT INTO \"user\" (\"email\") VALUES ('ann@example.com')"
        );

        $value = (string)(array_column($this->captureException($exception), 'value')[0] ?? '');

        $this->assertStringNotContainsString('ann@example.com', $value);
        $this->assertStringContainsString('Key (email)=(?) already exists.', $value);
        $this->assertStringContainsString('"user_email_key"', $value, 'Identifiers are kept.');
    }

    public function testDatabaseErrorMaskingCanBeTurnedOff(): void
    {
        $message = "Duplicate entry 'ann@example.com' for key 'email'";

        $values = $this->captureException(new IntegrityException($message), ['privacy' => ['maskDatabaseErrors' => false]]);

        $this->assertSame([$message], array_column($values, 'value'));
    }

    public function testDatabaseErrorsAreMaskedInBreadcrumbsToo(): void
    {
        $this->createWebApp([], [], [
            'log' => ['targets' => ['sentry' => ['class' => LogTarget::class]]],
        ])->getLog();

        Yii::error(new IntegrityException("Duplicate entry 'ann@example.com' for key 'email'"), 'yii\db\IntegrityException');
        Yii::error('a later problem', 'app');
        Yii::getLogger()->flush(true);

        $events = $this->transport->events();
        $this->assertCount(2, $events);
        $breadcrumbs = (string)json_encode($events[1]['breadcrumbs']['values'] ?? []);
        $this->assertStringContainsString("Duplicate entry ? for key 'email'", $breadcrumbs);
        $this->assertStringNotContainsString('ann@example.com', (string)json_encode($events));
    }

    // --- Yii's own log lines ------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function yiiLogLines(): array
    {
        return [
            'login' => ['yii\web\User::login', "User '42' logged in from 203.0.113.10 with duration 0.", "User '[Filtered PII]' logged in from [Filtered PII] with duration 0."],
            'login without session' => ['yii\web\User::login', "User '42' logged in from 203.0.113.10. Session not enabled.", "User '[Filtered PII]' logged in from [Filtered PII]. Session not enabled."],
            'login by cookie' => ['yii\web\User::loginByCookie', "User '42' logged in from 2001:db8::1 via cookie.", "User '[Filtered PII]' logged in from [Filtered PII] via cookie."],
            'logout' => ['yii\web\User::logout', "User '42' logged out from 203.0.113.10.", "User '[Filtered PII]' logged out from [Filtered PII]."],
            // Yii logs these as warnings, so they are events too.
            'invalid auth key in a cookie' => [
                'yii\web\User::getIdentityAndDurationFromCookie',
                "Invalid cookie auth key attempted for user '42' from 203.0.113.10: k3y-from-the-cookie",
                "Invalid cookie auth key attempted for user '[Filtered PII]' from [Filtered PII]: [Filtered PII]",
            ],
            'invalid auth key in the session' => [
                'yii\web\User::renewAuthStatus',
                "Invalid session auth key attempted for user '42' from 2001:db8::1: k3y-from-the-session",
                "Invalid session auth key attempted for user '[Filtered PII]' from [Filtered PII]: [Filtered PII]",
            ],
            'email' => ['yii\mail\BaseMailer::send', 'Sending email "Your order" to "ann@example.com, bob@example.com"', 'Sending email "Your order" to "[Filtered PII]"'],
            'outgoing HTTP call' => [
                'yii\httpclient\StreamTransport::send',
                "POST https://api.example.com/v1/charges?api_key=k1\nAuthorization: Bearer sk_live_1\n\ncard=4111111111111111",
                'POST https://api.example.com/v1/charges?api_key=[Filtered PII]',
            ],
            'database connection' => [
                'yii\db\Connection::open',
                'Opening DB connection: pgsql:host=db;dbname=app;password=db-secret',
                'Opening DB connection: pgsql:host=db;dbname=app;password=[Filtered PII]',
            ],
            // yii2-queue's LogBehavior: the exception and its stack trace, here of a job a worker ran in a process of
            // its own, with everything that process printed.
            'failed queue job' => [
                'yii\queue\Queue',
                "[42] app\\jobs\\ChargeJob (attempt: 1, PID: 77) is finished with error: ProcessFailedException: The command \"'php' 'yii' 'queue/exec' '42'\" failed.\n\n"
                    . "Exit Code: 5(Unknown error)\n\nOutput:\n================\nCharging card 4111111111111111\n in /app/vendor/yiisoft/yii2-queue/src/cli/Command.php:195\n"
                    . "Stack trace:\n#0 /app/vendor/yiisoft/yii2-queue/src/cli/Queue.php(146): yii\\queue\\cli\\Command->handleMessage(42, 'O:19:\"app\\\\jobs\\\\Ch...', 300, 1)",
                "[42] app\\jobs\\ChargeJob (attempt: 1, PID: 77) is finished with error: ProcessFailedException: The command \"'php' 'yii' 'queue/exec' '42'\" failed.",
            ],
        ];
    }

    /**
     * Yii writes these lines itself, so the application can't keep secrets and personal data out of them.
     *
     * @dataProvider yiiLogLines
     */
    public function testYiisOwnLogLinesAreScrubbed(string $category, string $line, string $expected): void
    {
        $this->assertSame([$expected], $this->breadcrumbsAfter([[$category, $line]]));
    }

    /**
     * @dataProvider yiiLogLines
     */
    public function testYiisOwnLogLinesAreScrubbedInEventsToo(string $category, string $line, string $expected): void
    {
        $this->assertSame($expected, $this->eventAfter($category, $line)['message'] ?? null);
    }

    public function testWithSendDefaultPiiYiisOwnLinesKeepPersonalDataButNotSecrets(): void
    {
        $breadcrumbs = $this->breadcrumbsAfter([
            ['yii\web\User::login', "User '42' logged in from 203.0.113.10 with duration 0."],
            ['yii\web\User::renewAuthStatus', "Invalid session auth key attempted for user '42' from 203.0.113.10: k3y"],
            ['yii\db\Connection::open', 'Opening DB connection: pgsql:host=db;password=db-secret'],
        ], ['sentrySettings' => ['send_default_pii' => true]]);

        $this->assertSame([
            "User '42' logged in from 203.0.113.10 with duration 0.",
            "Invalid session auth key attempted for user '42' from 203.0.113.10: [Filtered PII]",
            'Opening DB connection: pgsql:host=db;password=[Filtered PII]',
        ], $breadcrumbs);
    }

    /**
     * With the SDK's `data_collection` option, the SDK ignores `send_default_pii`: its `user_info` decides.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function dataCollectionPolicies(): array
    {
        return [
            'user info collected' => [['data_collection' => ['user_info' => true]], true],
            'user info not collected, despite send_default_pii' => [['send_default_pii' => true, 'data_collection' => ['user_info' => false]], false],
        ];
    }

    /**
     * @dataProvider dataCollectionPolicies
     *
     * @param array<string, mixed> $sentrySettings
     */
    public function testWithTheDataCollectionOptionYiisOwnLinesFollowItsUserInfo(array $sentrySettings, bool $personalDataSent): void
    {
        $line = "User '42' logged in from 203.0.113.10 with duration 0.";

        $breadcrumbs = $this->breadcrumbsAfter([['yii\web\User::login', $line]], ['sentrySettings' => $sentrySettings]);

        $this->assertSame([$personalDataSent ? $line : "User '[Filtered PII]' logged in from [Filtered PII] with duration 0."], $breadcrumbs);
    }

    /**
     * The SDK collects the cookies itself under its data_collection option, leaving out those named like its own list
     * of words ("session", "csrf", ...): Yii's cookies are added to it, by the names the application gives them.
     *
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function dataCollectionCookieSettings(): array
    {
        return [
            'no cookie setting' => [null, true],
            'terms of its own' => [['terms' => ['theme']], true],
            'a deny list object' => [KeyValueCollectionBehavior::denyList(['theme']), true],
            'an allow list' => [['mode' => KeyValueCollectionBehavior::MODE_ALLOW_LIST, 'terms' => ['lang']], false],
            'no cookies at all' => [['mode' => KeyValueCollectionBehavior::MODE_OFF], false],
        ];
    }

    /**
     * @dataProvider dataCollectionCookieSettings
     *
     * @param mixed $cookies the data_collection option's cookies setting
     */
    public function testWithTheDataCollectionOptionYiisSecretCookiesAreLeftOut($cookies, bool $denied): void
    {
        $app = $this->createWebApp(
            ['sentrySettings' => ['data_collection' => $cookies === null ? [] : ['cookies' => $cookies]]],
            [],
            ['session' => ['name' => 'advanced-frontend']]
        );

        $behavior = $this->dataCollectionCookies($app);
        $terms = $behavior->getTerms();
        if (!$denied) {
            // As the application set it: it lists what may be collected, or nothing is.
            $this->assertNotSame(KeyValueCollectionBehavior::MODE_DENY_LIST, $behavior->getMode());
            $this->assertNotContains('advanced-frontend', $terms);

            return;
        }
        $this->assertSame(KeyValueCollectionBehavior::MODE_DENY_LIST, $behavior->getMode());
        foreach (['advanced-frontend', '_csrf', '_identity'] as $name) {
            $this->assertContains($name, $terms);
        }
        if ($cookies !== null) {
            $this->assertContains('theme', $terms, "The application's own terms are kept.");
        }
    }

    public function testWithTheDataCollectionOptionYiisCookiesAreLeftToItWhenSecretsAreSent(): void
    {
        $app = $this->createWebApp(['sentrySettings' => ['data_collection' => []], 'privacy' => ['scrubSecrets' => false]]);

        $this->assertSame([], $this->dataCollectionCookies($app)->getTerms());
    }

    public function testYiisOwnLogLinesCanBeSentAsWritten(): void
    {
        $line = "User '42' logged in from 203.0.113.10 with duration 0.";

        $this->assertSame([$line], $this->breadcrumbsAfter([['yii\web\User::login', $line]], ['privacy' => ['scrubYiiLogs' => false]]));
    }

    public function testYiisOwnLogLinesCanBeSentAsWrittenInEventsToo(): void
    {
        $line = "Invalid cookie auth key attempted for user '42' from 203.0.113.10: k3y";

        $event = $this->eventAfter('yii\web\User::getIdentityAndDurationFromCookie', $line, ['privacy' => ['scrubYiiLogs' => false]]);

        $this->assertSame($line, $event['message'] ?? null);
    }

    public function testYiisOwnLogLinesAreScrubbedInSentryLogsToo(): void
    {
        $this->breadcrumbsAfter([['yii\web\User::login', "User '42' logged in from 203.0.113.10 with duration 0."]], [], ['sentryLogs' => true]);

        $this->assertContains(
            "User '[Filtered PII]' logged in from [Filtered PII] with duration 0.",
            array_column($this->transport->logs(), 'body')
        );
    }

    // --- Helpers ------------------------------------------------------------------------------------------------

    /**
     * Logs the lines, then an error, and returns the messages of the error event's breadcrumbs.
     *
     * @param list<array{0: string, 1: string}> $lines categories and messages, logged at the info level
     * @param array<string, mixed> $sentry
     * @param array<string, mixed> $target LogTarget settings
     *
     * @return list<string>
     */
    private function breadcrumbsAfter(array $lines, array $sentry = [], array $target = []): array
    {
        // Outgoing calls are breadcrumbs of their own while HTTP client tracing is on, and so are queued and failed jobs:
        // test the lines Yii and yii2-queue log instead.
        $sentry['tracing'] = array_merge(['httpClient' => false], $sentry['tracing'] ?? []);
        $sentry['queue'] = array_merge(['breadcrumbs' => false, 'errors' => false], $sentry['queue'] ?? []);
        $this->createWebApp($sentry, [], [
            'log' => ['targets' => ['sentry' => array_merge(['class' => LogTarget::class], $target)]],
        ])->getLog();

        foreach ($lines as [$category, $line]) {
            Yii::info($line, $category);
        }
        Yii::error('a later problem', 'app');
        Yii::getLogger()->flush(true);

        return array_column($this->singleEvent()['breadcrumbs']['values'] ?? [], 'message');
    }

    private function dataCollectionCookies(Application $app): KeyValueCollectionBehavior
    {
        $client = $app->get('sentry')->getHub()->getClient();
        $this->assertNotNull($client);
        $dataCollection = $client->getOptions()->getDataCollection();
        $this->assertNotNull($dataCollection);

        return $dataCollection->getCookies();
    }

    /**
     * Logs the line as a warning, which makes it an event, and returns that event.
     *
     * @param array<string, mixed> $sentry
     *
     * @return array<string, mixed>
     */
    private function eventAfter(string $category, string $line, array $sentry = []): array
    {
        $sentry['tracing'] = array_merge(['httpClient' => false], $sentry['tracing'] ?? []);
        $sentry['queue'] = array_merge(['breadcrumbs' => false, 'errors' => false], $sentry['queue'] ?? []);
        $this->createWebApp($sentry, [], [
            'log' => ['targets' => ['sentry' => ['class' => LogTarget::class]]],
        ])->getLog();

        Yii::warning($line, $category);
        Yii::getLogger()->flush(true);

        return $this->singleEvent();
    }

    /**
     * @throws RuntimeException always
     */
    private static function validatePassword(string $password, string $email): void
    {
        throw new RuntimeException('Hash is invalid.');
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $sentry
     *
     * @return array<string, mixed>
     */
    private function postForm(array $fields, array $sentry = []): array
    {
        $app = $this->createWebApp($sentry, $this->formPost($fields));
        $app->getRequest()->setRawBody(http_build_query($fields));

        return $this->captureFromAction($app);
    }

    /**
     * @param array<string, mixed> $sentry
     *
     * @return array<string, mixed>
     */
    private function sendRaw(string $method, string $body, string $contentType, array $sentry = []): array
    {
        $_POST = [];
        $app = $this->createWebApp($sentry, [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => '/site/post-log',
            'CONTENT_TYPE' => $contentType,
        ]);
        $app->getRequest()->setRawBody($body);

        return $this->captureFromAction($app);
    }

    /**
     * @param array<string, mixed> $sentry
     * @param array<string, mixed> $server
     * @param array<string, mixed> $components
     *
     * @return array<string, mixed>
     */
    private function get(array $sentry = [], array $server = [], array $components = []): array
    {
        return $this->captureFromAction($this->createWebApp($sentry, $server, $components));
    }

    /**
     * @param array<string, mixed> $sentry
     *
     * @return list<array<string, mixed>> the event's exception values, innermost first
     */
    private function captureException(\Throwable $exception, array $sentry = []): array
    {
        $app = $this->createWebApp($sentry);
        $app->get('sentry')->getHub()->captureException($exception);

        return $this->singleEvent()['exception']['values'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureFromAction(Application $app): array
    {
        $app->requestedAction = new Action('post-log', new Controller('site', $app));
        $app->get('sentry')->getHub()->captureMessage('something happened');

        return $this->singleEvent();
    }
}
