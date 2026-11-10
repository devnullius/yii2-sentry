<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Throwable;
use Yii;
use yii\helpers\ArrayHelper;
use yii\web\Request;
use function in_array;

/**
 * Adds the current Yii web request to Sentry events, with control over personal data in headers and the body.
 */
class Integration implements IntegrationInterface
{
    /**
     * List of HTTP methods for whom the request body must be passed to the Sentry
     *
     * @var string[]
     */
    public $httpMethodsWithRequestBody = ['POST', 'PUT', 'PATCH'];
    /**
     * List of headers, that should be stripped. Use lower case.
     *
     * @var string[]
     */
    public $stripHeaders = ['cookie', 'set-cookie'];
    /**
     * List of headers, that can contain Personal data. Use lower case.
     *
     * The defaults cover credentials and the visitor's IP address as forwarded by proxies.
     *
     * @var string[]
     */
    public $piiHeaders = ['authorization', 'proxy-authorization', 'x-forwarded-for', 'x-real-ip', 'remote_addr'];
    /**
     * List of routes with keys, that must be stripped from request body
     * For example:
     *
     * ```php
     * [
     *     'controller/action' => [
     *         'field_1',
     *     ],
     *     'account/login' => [
     *         'email',
     *         'password',
     *     ],
     *     // Nested fields, e.g. the ones posted by a Yii ActiveForm as LoginForm[password]
     *     'site/login' => [
     *         'LoginForm' => [
     *             'password',
     *         ],
     *     ],
     * ]
     * ```
     *
     * @var array
     */
    public $piiBodyFields = [];
    /**
     * String to replace PII with.
     *
     * @var string|null
     */
    public $piiReplaceText = '[Filtered PII]';

    /**
     * Options to use instead of the client's ones. Normally left unset.
     *
     * @var Options|null
     */
    protected $options;

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
     * Adds the current request to the event.
     *
     * This runs while errors are being reported, so it never throws: on failure, the event is kept as it is and the
     * failure is logged as a warning.
     */
    public function processEvent(Event $event, Options $options): Event
    {
        try {
            $this->applyToEvent($event, $options);
        } catch (Throwable $exception) {
            Yii::warning('Unable to add the request to the Sentry event: ' . $exception, __METHOD__);
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

        $requestMethod = $request->getMethod();

        $requestData = [
            'url' => $this->getRequestUrl($request),
            'method' => $requestMethod,
            'query_string' => $request->getQueryString(),
        ];

        // Process headers, cookies, etc. Done the same way as in RequestIntegration, but using Yii's stuff.
        /** @see \Sentry\Integration\RequestIntegration */
        if ($options->shouldSendDefaultPii()) {
            $remoteIp = $request->getRemoteIP();
            if ($remoteIp !== null) {
                $requestData['env']['REMOTE_ADDR'] = $remoteIp;
            }

            $requestData['cookies'] = $_COOKIE;
            $requestData['headers'] = $request->getHeaders()->toArray();
        } else {
            $requestData['headers'] = $this->processHeaders($request->getHeaders()->toArray());
        }

        try {
            $this->addRequestBody($event, $request, $options, $requestData);
        } catch (Throwable $exception) {
            // Without a "data" key, the SDK's RequestIntegration would add the raw body, unfiltered.
            $requestData['data'] = 'Not available: the request body could not be read.';

            throw $exception;
        } finally {
            // Keep the rest of the request data, even if reading the body failed.
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
     * @param array $headers
     *
     * @return array
     */
    protected function processHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $header => $value) {
            // Strip headers
            if (in_array(strtolower((string)$header), $this->stripHeaders, true)) {
                continue;
            }

            // Check for PII in headers
            if (in_array(strtolower((string)$header), $this->piiHeaders, true)) {
                $result[$header] = $this->piiReplaceText;
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
     * @param array<string, mixed> $requestData
     */
    private function addRequestBody(Event $event, Request $request, Options $options, array &$requestData): void
    {
        if (!in_array($request->getMethod(), $this->httpMethodsWithRequestBody, true)) {
            return;
        }

        $rawBody = $request->getRawBody();
        if ($rawBody === '') {
            return;
        }

        $filterFields = !$options->shouldSendDefaultPii() && $this->piiBodyFields !== [];
        // No action is resolved yet when a request fails before routing, e.g. in bootstrap or "beforeRequest".
        $action = Yii::$app->requestedAction;
        $actionId = $action !== null ? $action->getUniqueId() : null;

        if ($filterFields && $actionId === null) {
            // No route's rules can be applied: leave the body out rather than send fields they may cover.
            $requestData['data'] = 'Not available: the route was not resolved, so piiBodyFields could not be applied.';

            return;
        }

        $bodyParams = $request->getBodyParams();
        if (!is_array($bodyParams)) {
            // E.g. a JsonParser configured with asArray = false.
            $bodyParams = ArrayHelper::toArray($bodyParams);
        }

        if ($filterFields && isset($this->piiBodyFields[$actionId])) {
            $requestData['data'] = 'Not available due to PII. See "decodedParams" in Additional data block.';

            $this->removeKeysFromArrayRecursively($bodyParams, $this->piiBodyFields[$actionId]);
        } else {
            $requestData['data'] = $rawBody;
        }

        // Merge: the scope's extras, e.g. the log context added by LogTarget, are already on the event.
        $event->setExtra(array_merge($event->getExtra(), [
            'decodedParams' => $bodyParams,
        ]));
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
}
