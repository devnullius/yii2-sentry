<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Throwable;
use Yii;
use yii\base\Application;
use yii\helpers\ArrayHelper;
use yii\web\Request;
use yii\web\Session;
use yii\web\User;
use function in_array;

/**
 * Adds the current Yii web request to Sentry events, and keeps secrets and personal data out of events: out of the
 * request (body, query string, headers and cookies), out of the function arguments in stack traces, and out of
 * database error messages.
 *
 * Secrets are scrubbed even when the SDK's `send_default_pii` option is on; personal data is hidden while it is off.
 * With the SDK's `data_collection` option, the SDK collects the request and function arguments under that policy
 * instead, and only database errors are masked here; elsewhere, e.g. in Yii's log lines, personal data is then hidden
 * while that option doesn't collect user info.
 */
class Integration implements IntegrationInterface
{
    /**
     * The default [[secretFields]].
     */
    public const SECRET_FIELDS = [
        'password', 'passwd', 'passphrase', 'pwd', 'secret', 'token', 'auth', 'authorization', 'authentication',
        'authkey', 'apikey', 'accesskey', 'privatekey', 'key', 'jwt', 'bearer', 'sso', 'saml', 'csrf', 'xsrf',
        'credential', 'session', 'sessid', 'sid', 'identity', 'signature', 'otp', 'totp', 'pin',
        'card', 'creditcard', 'cardnumber', 'cvv', 'cvc', 'securitycode',
    ];

    /**
     * The default [[personalFields]].
     */
    public const PERSONAL_FIELDS = [
        'email', 'mail', 'phone', 'mobile', 'tel', 'fax', 'firstname', 'lastname', 'surname', 'fullname',
        'middlename', 'address', 'street', 'postcode', 'postalcode', 'zipcode', 'zip', 'birth', 'dob', 'iban',
        'bankaccount', 'accountnumber', 'passport', 'nationalid', 'ssn', 'socialsecurity', 'taxid', 'ip',
    ];

    /**
     * Sent instead of a request body that is left out, with the reason.
     */
    private const NOT_SENT = '[not sent: %s]';

    /**
     * @var bool Scrub secrets: the values of fields, query parameters, headers, cookies and function arguments whose
     * names are in [[secretFields]], Yii's session, CSRF and identity cookies, and the session ID in the log lines of
     * [[LogTarget::$aggregate]] mode. Secrets are scrubbed even when the SDK's `send_default_pii` option is on.
     */
    public $scrubSecrets = true;
    /**
     * @var string[] Names of fields that hold secrets. Case and separators don't matter. A name of five characters
     * or more matches anywhere ("token" matches "accessToken"), a shorter one only as a whole word ("key" matches
     * "api_key" and "apiKey", not "monkey"). To add names, merge them with the defaults:
     * `array_merge(Integration::SECRET_FIELDS, ['my_name'])`.
     */
    public $secretFields = self::SECRET_FIELDS;
    /**
     * @var bool|null Hide personal data: the values of fields, query parameters, headers and function arguments whose
     * names are in [[personalFields]] or [[piiBodyFields]], and the headers in [[piiHeaders]]. By default (null),
     * it is hidden while the SDK's `send_default_pii` option is off, or, when the SDK's `data_collection` option is
     * set, while that doesn't collect user info (`user_info`); `true` always hides it, `false` never does.
     * The visitor's IP address and cookies are only sent with `send_default_pii`.
     */
    public $scrubPersonalData;
    /**
     * @var string[] Names of fields that hold personal data, matched like [[secretFields]].
     */
    public $personalFields = self::PERSONAL_FIELDS;
    /**
     * More personal data fields, for the request body of specific routes; hidden like [[personalFields]].
     *
     * A name is matched like the names in [[personalFields]], at any depth of the body. A name with a list limits
     * the names to the fields under it:
     *
     * ```php
     * [
     *     'account/register' => ['nickname', 'company'],
     *     // Only the "nickname" posted by a Yii ActiveForm as Profile[nickname].
     *     'account/profile' => ['Profile' => ['nickname']],
     * ]
     * ```
     *
     * When a request fails before its route is known, e.g. in bootstrap, the body is left out.
     *
     * @var array<string, array<array-key, mixed>>
     */
    public $piiBodyFields = [];
    /**
     * @var string[] Headers that hold personal data (the visitor's IP address, as forwarded by proxies), hidden like
     * [[personalFields]]. Headers named after the [[personalFields]] are hidden too. Lower case.
     */
    public $piiHeaders = ['forwarded', 'x-forwarded-for', 'x-real-ip', 'remote_addr'];
    /**
     * @var string[] Headers that are never sent. Lower case. The cookies are sent apart, with `send_default_pii`.
     */
    public $stripHeaders = ['cookie', 'set-cookie'];
    /**
     * @var bool Send request bodies that can't be read as fields (XML, plain text, invalid JSON) as text. Secrets and
     * personal data in them can't be found, so they are sent as they are.
     */
    public $sendUnparsedBodies = false;
    /**
     * @var string[] HTTP methods whose request body is sent.
     */
    public $httpMethodsWithRequestBody = ['POST', 'PUT', 'PATCH'];
    /**
     * @var bool Replace the values in database error messages (of `yii\db\Exception` and `PDOException`) with "?",
     * e.g. "Duplicate entry ? for key 'email'". Names of tables, columns and keys are kept.
     */
    public $maskDatabaseErrors = true;
    /**
     * @var bool Scrub the log lines Yii itself writes, which become breadcrumbs and Sentry Logs: outgoing HTTP calls
     * keep only their method and URL (Yii logs every header and the body); logins and logouts lose the user ID and
     * IP address, and emails their recipients, while personal data is hidden; a password in the DSN of a database
     * connection is always scrubbed; yii2-queue's line about a failed job keeps only its first line, without the
     * stack trace or what the job's process printed. Your application's own log lines are sent as they are written.
     */
    public $scrubYiiLogs = true;
    /**
     * @var string|null Replaces scrubbed values.
     */
    public $piiReplaceText = '[Filtered PII]';

    /**
     * Options to use instead of the client's ones. Normally left unset.
     *
     * @var Options|null
     */
    protected $options;

    /**
     * @var Scrubber|null the scrubber for the event being processed
     */
    private $scrubber;

    /**
     * {@inheritdoc}
     */
    public function setupOnce(): void
    {
        // The SDK calls this once per integration class and process, so the processor must not use $this: it looks
        // up the instance registered on the current client, which carries that client's settings.
        $integrationClass = static::class;

        Scope::addGlobalEventProcessor(static function (Event $event) use ($integrationClass): Event {
            $currentHub = SentrySdk::getCurrentHub();
            $client = $currentHub->getClient();

            if ($client === null) {
                return $event;
            }

            $integration = $currentHub->getIntegration($integrationClass);

            // The client bound to the current hub may not have this integration enabled.
            if (!$integration instanceof self) {
                return $event;
            }

            return $integration->processEvent($event, $integration->options ?? $client->getOptions());
        });
    }

    /**
     * Adds the current request to the event, and scrubs secrets and personal data from it.
     *
     * This runs while errors are being reported, so it never throws. Each part is done even if another one fails; a
     * failure leaves that part of the event as it was, and is logged as a warning.
     */
    public function processEvent(Event $event, Options $options): Event
    {
        $type = (string)$event->getType();
        if ($type !== 'event' && $type !== 'transaction') {
            return $event;
        }

        $this->scrubber = null;

        self::attempt('add the request to', function () use ($event, $options): void {
            $this->applyToEvent($event, $options);
        });
        if ($options->getDataCollection() === null) {
            self::attempt('scrub the function arguments in', function () use ($event, $options): void {
                $this->scrubFunctionArguments($event, $this->scrubber($options));
            });
        }
        if ($this->maskDatabaseErrors) {
            self::attempt('mask the database errors in', static function () use ($event): void {
                self::maskDatabaseErrorsIn($event);
            });
        }

        return $event;
    }

    protected function applyToEvent(Event $event, Options $options): void
    {
        // With the SDK's data_collection option, the SDK's own RequestIntegration collects the request under that
        // policy; adding the request here would send what the policy leaves out.
        if ($options->getDataCollection() !== null) {
            return;
        }

        $request = Yii::$app !== null ? Yii::$app->getRequest() : null;

        // Skip if the current request is made via console.
        if (!$request instanceof Request || $request->getIsConsoleRequest()) {
            return;
        }

        $scrubber = $this->scrubber($options);
        $requestData = [];

        try {
            $requestData['url'] = $this->getRequestUrl($request);
            $requestData['method'] = $request->getMethod();
            $requestData['query_string'] = $scrubber->scrubQueryString($request->getQueryString());
            $requestData['headers'] = $this->processHeaders($request->getHeaders()->toArray());

            if ($options->shouldSendDefaultPii()) {
                $remoteIp = $request->getRemoteIP();
                if ($remoteIp !== null) {
                    $requestData['env']['REMOTE_ADDR'] = $remoteIp;
                }

                $requestData['cookies'] = $scrubber->scrub($_COOKIE);
            }

            // Like the SDK: request bodies are only relevant for errors.
            if ((string)$event->getType() === 'event') {
                $body = $this->requestBody($request, $options, $scrubber);
                if ($body !== null) {
                    $requestData['data'] = $body;
                }
            }
        } catch (Throwable $exception) {
            // Without these, the SDK's RequestIntegration would add its own, unfiltered.
            $requestData += ['query_string' => '', 'headers' => [], 'data' => sprintf(self::NOT_SENT, 'the request could not be read')];
            if ($options->shouldSendDefaultPii()) {
                $requestData += ['cookies' => []];
            }

            throw $exception;
        } finally {
            // Keep what could be read, even if reading the rest failed.
            $event->setRequest($requestData);
        }

        if ($options->shouldSendDefaultPii()) {
            // Unlike REMOTE_ADDR, this is the client's IP behind the proxies listed in Request::$trustedHosts.
            $this->addUserIpAddress($event, $request->getUserIP(), $request->getRemoteIP());
        }
    }

    /**
     * @return string the absolute URL without the query string, which is sent separately
     */
    protected function getRequestUrl(Request $request): string
    {
        $url = $request->getUrl();

        $queryPosition = strpos($url, '?');
        if ($queryPosition !== false) {
            $url = substr($url, 0, $queryPosition);
        }

        $hostInfo = $request->getHostInfo();

        return $hostInfo !== null ? $hostInfo . $url : $url;
    }

    /**
     * Leaves out the [[stripHeaders]], and scrubs secret headers, and personal data ones while personal data is
     * hidden. The query string of the "Referer" header is scrubbed like the request's.
     *
     * @param array $headers
     *
     * @return array
     */
    protected function processHeaders(array $headers): array
    {
        $scrubber = $this->scrubber();
        $stripHeaders = array_map('strtolower', $this->stripHeaders);
        $piiHeaders = array_map('strtolower', $this->piiHeaders);
        $result = [];

        foreach ($headers as $header => $value) {
            $name = strtolower((string)$header);

            if (in_array($name, $stripHeaders, true)) {
                continue;
            }

            // A Cookie header that is kept would carry the session ID.
            $isSecret = $scrubber->isSecret($name) || ($this->scrubSecrets && ($name === 'cookie' || $name === 'set-cookie'));
            $isPersonal = $scrubber->hidesPersonalData() && (in_array($name, $piiHeaders, true) || $scrubber->isPersonal($name));

            if ($isSecret || $isPersonal) {
                $result[$header] = $this->piiReplaceText;
            } elseif ($name === 'referer') {
                $result[$header] = is_array($value) ? array_map([$scrubber, 'scrubUrl'], array_map('strval', $value)) : $scrubber->scrubUrl((string)$value);
            } else {
                $result[$header] = $value;
            }
        }

        return $result;
    }

    /**
     * Replaces the values of the given keys with [[piiReplaceText]]. A string key with an array value describes
     * nested fields. Keys missing from the body are skipped; the body comes from the client and may have any shape.
     */
    protected function removeKeysFromArrayRecursively(array &$array, array $keysToRemove): void
    {
        foreach ($keysToRemove as $key => $value) {
            if (is_string($key) && is_array($value)) {
                if (isset($array[$key]) && is_array($array[$key])) {
                    $this->removeKeysFromArrayRecursively($array[$key], $value);
                }
            } elseif ((is_string($value) || is_int($value)) && isset($array[$value])) {
                $array[$value] = $this->piiReplaceText;
            }
        }
    }

    /**
     * @return array<array-key, mixed>|string|null what to send as the body: its fields, its text, a note saying why
     *                                             it is left out, or null when there is no body to send
     */
    private function requestBody(Request $request, Options $options, Scrubber $scrubber)
    {
        $rawBody = $request->getRawBody();
        // For a multipart form, PHP has read the body into $_POST and $_FILES, and the raw body is empty.
        if ($rawBody === '' && $_POST === [] && $_FILES === []) {
            return null;
        }

        $limit = self::requestBodyLimit($options);
        if ($limit === 0) {
            return null;
        }

        if (!in_array($request->getMethod(), $this->httpMethodsWithRequestBody, true)) {
            return sprintf(self::NOT_SENT, $request->getMethod() . ' is not in httpMethodsWithRequestBody');
        }

        if ($limit !== null && strlen($rawBody) > $limit) {
            return sprintf(self::NOT_SENT, 'larger than max_request_body_size');
        }

        $routeRules = [];
        if ($scrubber->hidesPersonalData() && $this->piiBodyFields !== []) {
            // No action is resolved yet when a request fails before routing, e.g. in bootstrap or "beforeRequest".
            $action = Yii::$app !== null ? Yii::$app->requestedAction : null;
            if ($action === null) {
                return sprintf(self::NOT_SENT, 'the route is unknown, so piiBodyFields could not be applied');
            }

            $routeRules = $this->piiBodyFields[$action->getUniqueId()] ?? [];
        }

        try {
            $fields = $this->bodyFields($request, $rawBody);
        } catch (Throwable $exception) {
            // E.g. Yii's JsonParser, on invalid JSON.
            $fields = 'the body could not be parsed';
        }

        if (is_string($fields)) {
            return $this->sendUnparsedBodies ? $rawBody : sprintf(self::NOT_SENT, $fields);
        }

        if ($rawBody === '' && $limit !== null && strlen(http_build_query($fields)) > $limit) {
            return sprintf(self::NOT_SENT, 'larger than max_request_body_size');
        }

        return $this->scrubBody($fields, $scrubber, (array)$routeRules);
    }

    /**
     * @return array<array-key, mixed>|string the body's fields, or why it can't be read as fields
     */
    private function bodyFields(Request $request, string $rawBody)
    {
        // The content type the way Yii looks up its parsers.
        $contentType = (string)$request->getContentType();
        $parameters = strpos($contentType, ';');
        if ($parameters !== false) {
            $contentType = substr($contentType, 0, $parameters);
        }
        $mediaType = strtolower(trim($contentType));

        // Yii parses the body itself only with a parser, or when the form was posted: other bodies would come out
        // as garbled fields, e.g. a JSON body as a single field named after its whole text.
        $parsedByYii = isset($request->parsers[$contentType]) || isset($request->parsers['*']) || isset($_POST[$request->methodParam]);
        $isForm = $mediaType === 'application/x-www-form-urlencoded' || ($mediaType === 'multipart/form-data' && $rawBody === '');

        if ($parsedByYii || $isForm) {
            $fields = $request->getBodyParams();

            // E.g. a JsonParser configured with asArray = false.
            return is_array($fields) ? $fields : ArrayHelper::toArray($fields);
        }

        if ($mediaType === 'application/json' || preg_match('{^application/[^/;\s]+\+json$}', $mediaType) === 1) {
            $fields = json_decode($rawBody, true);

            return is_array($fields) ? $fields : 'the body is not a JSON object or array';
        }

        return 'the body is not form data or JSON';
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param array<array-key, mixed> $routeRules
     *
     * @return array<array-key, mixed>
     */
    private function scrubBody(array $fields, Scrubber $scrubber, array $routeRules): array
    {
        $names = [];
        $nested = [];
        foreach ($routeRules as $key => $rule) {
            if (is_string($key) && is_array($rule)) {
                $nested[$key] = $rule;
            } elseif (is_string($rule) || is_int($rule)) {
                $names[] = (string)$rule;
            }
        }

        if ($nested !== []) {
            $this->removeKeysFromArrayRecursively($fields, $nested);
        }

        return $scrubber->scrub($fields, $names !== [] ? new FieldMatcher($names) : null);
    }

    private function scrubFunctionArguments(Event $event, Scrubber $scrubber): void
    {
        $stacktraces = [$event->getStacktrace()];
        foreach ($event->getExceptions() as $exception) {
            $stacktraces[] = $exception->getStacktrace();
        }

        foreach ($stacktraces as $stacktrace) {
            if ($stacktrace === null) {
                continue;
            }

            foreach ($stacktrace->getFrames() as $frame) {
                $arguments = $frame->getVars();
                if ($arguments !== []) {
                    $frame->setVars($scrubber->scrub($arguments));
                }
            }
        }
    }

    private static function maskDatabaseErrorsIn(Event $event): void
    {
        foreach ($event->getExceptions() as $exception) {
            if (SqlMasker::isDatabaseError($exception->getType())) {
                $exception->setValue(SqlMasker::maskErrorMessage($exception->getValue()));
            }
        }
    }

    /**
     * Creates the scrubber for these settings and the client's options.
     *
     * @internal
     */
    public function createScrubber(?Options $options): Scrubber
    {
        return new Scrubber(
            $this->scrubSecrets ? new FieldMatcher($this->secretFields) : null,
            $this->scrubSecrets ? self::yiiSecretNames() : [],
            $this->hidesPersonalData($options) ? new FieldMatcher($this->personalFields) : null,
            $this->piiReplaceText
        );
    }

    /**
     * Removes secrets and personal data from a line Yii itself logged, see [[scrubYiiLogs]].
     *
     * @internal
     */
    public function scrubLogMessage(string $category, string $text, Options $options): string
    {
        if (!$this->scrubYiiLogs || strpos($category, 'yii\\') !== 0) {
            return $text;
        }

        $replacement = (string)$this->piiReplaceText;
        $replace = static function (string $pattern, callable $replace, string $text) use ($replacement): string {
            // On a regular expression failure, show nothing rather than the data.
            return preg_replace_callback($pattern, $replace, $text) ?? $replacement;
        };

        if (strpos($category, 'yii\\httpclient\\') === 0) {
            // The method and URL; then every header, and the body.
            return $this->createScrubber($options)->scrubUrl(explode("\n", $text, 2)[0]);
        }

        if ($category === 'yii\\queue\\Queue') {
            // yii2-queue's LogBehavior: the job and the exception it failed with; then the stack trace, with
            // arguments, and the job's data the exception may hold.
            return explode("\n", FailedJobText::withoutJobData($text), 2)[0];
        }

        if ($category === 'yii\\db\\Connection::open' && $this->scrubSecrets) {
            // E.g. "Opening DB connection: pgsql:host=db;dbname=app;password=secret"
            return $replace('/((?:^|[;:])\s*(?:password|pwd)\s*=)[^;]*/i', static function (array $match) use ($replacement): string {
                return $match[1] . $replacement;
            }, $text);
        }

        // Yii logs a line for every SQL query: build nothing for the many lines left as they are.
        if (!$this->hidesPersonalData($options)) {
            return $text;
        }

        if (strpos($category, 'yii\\web\\User::') === 0) {
            // E.g. "User '42' logged in from 203.0.113.10 with duration 0."
            $text = $replace("/^User '.*?' (logged (?:in|out) from )/", static function (array $match) use ($replacement): string {
                return "User '$replacement' " . $match[1];
            }, $text);

            return $replace('/(logged (?:in|out) from )[0-9A-Fa-f:.]*[0-9A-Fa-f]/', static function (array $match) use ($replacement): string {
                return $match[1] . $replacement;
            }, $text);
        }

        if ($category === 'yii\\mail\\BaseMailer::send') {
            // E.g. 'Sending email "Your order" to "ann@example.com"'
            return $replace('/^(Sending email ".*" to ")(.*)(")$/s', static function (array $match) use ($replacement): string {
                return $match[1] . $replacement . $match[3];
            }, $text);
        }

        return $text;
    }

    /**
     * @param Options|null $options without them, personal data is hidden
     */
    private function scrubber(?Options $options = null): Scrubber
    {
        if ($this->scrubber === null) {
            $this->scrubber = $this->createScrubber($options);
        }

        return $this->scrubber;
    }

    /**
     * Whether personal data is hidden: as [[scrubPersonalData]] says, or by default while the SDK may not send it.
     * That is without `send_default_pii`, or, when the SDK's `data_collection` option is set (the SDK then ignores
     * `send_default_pii`), while that doesn't collect user info.
     *
     * @param Options|null $options without them, personal data is hidden
     */
    private function hidesPersonalData(?Options $options): bool
    {
        if ($this->scrubPersonalData !== null) {
            return (bool)$this->scrubPersonalData;
        }

        return !DataCollectionPolicy::fromOptions($options)->shouldCollectUserInfo();
    }

    private function addUserIpAddress(Event $event, ?string $ipAddress, ?string $remoteIp): void
    {
        if ($ipAddress === null || $ipAddress === '') {
            return;
        }

        $user = $event->getUser();

        if ($user === null) {
            $event->setUser(UserDataBag::createFromUserIpAddress($ipAddress));
        } elseif ($user->getIpAddress() === null || $user->getIpAddress() === $remoteIp) {
            // REMOTE_ADDR is the SDK's own fallback (with a reused client, the SDK's RequestIntegration runs first):
            // replace it, but keep an address that was set explicitly.
            $user->setIpAddress($ipAddress);
        }
    }

    /**
     * The names Yii keeps secrets under, as the application configures them: the session cookie, the CSRF cookie and
     * form field, and the identity cookie of "remember me" logins.
     *
     * @return string[]
     */
    private static function yiiSecretNames(): array
    {
        $names = [];

        $sessionName = session_name();
        if ($sessionName !== false) {
            $names[] = $sessionName;
        }

        $app = Yii::$app;
        if ($app === null) {
            return $names;
        }

        $request = $app->getRequest();
        if ($request instanceof Request) {
            $names[] = $request->csrfParam;
        }

        $names[] = self::componentSetting($app, 'session', ['name'], static function ($session): ?string {
            return $session instanceof Session ? $session->getName() : null;
        });
        $names[] = self::componentSetting($app, 'user', ['identityCookie', 'name'], static function ($user): ?string {
            return $user instanceof User ? ($user->identityCookie['name'] ?? null) : null;
        }) ?? '_identity';

        return array_values(array_filter($names, static function ($name): bool {
            return is_string($name) && $name !== '';
        }));
    }

    /**
     * Reads a setting of an application component without creating the component: from the component once it
     * exists, otherwise from its configuration.
     *
     * @param string[] $path the setting's keys in the component's configuration
     * @param callable(object): ?string $read reads the setting from the component
     */
    private static function componentSetting(Application $app, string $id, array $path, callable $read): ?string
    {
        if ($app->has($id, true)) {
            return $read($app->get($id));
        }

        $value = $app->getComponents()[$id] ?? null;
        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @return int|null the most bytes of a request body to send (0 for none), or null without a limit, as the SDK's
     *                  `max_request_body_size` option sets it
     */
    private static function requestBodyLimit(Options $options): ?int
    {
        switch ($options->getMaxRequestBodySize()) {
            case 'small':
                return 1000;
            case 'medium':
                return 10000;
            case 'always':
                return null;
            default:
                return 0;
        }
    }

    private static function attempt(string $what, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $exception) {
            Yii::warning("Unable to $what the Sentry event: " . $exception, __METHOD__);
        }
    }
}
