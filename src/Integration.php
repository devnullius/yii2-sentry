<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Yii;
use function in_array;

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
     * @var string[]
     */
    public $piiHeaders = ['authorization', 'remote_addr'];
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
     *     ]
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
     * @var Options
     */
    protected $options;

    /**
     * {@inheritdoc}
     */
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(function (Event $event): Event {
            $currentHub = SentrySdk::getCurrentHub();
            $integration = $currentHub->getIntegration(self::class);
            $client = $currentHub->getClient();
            $options = $this->options ?? $client->getOptions();

            if (!$integration instanceof self) {
                return $event;
            }

            $this->applyToEvent($event, $options);

            return $event;
        });
    }

    protected function applyToEvent(Event $event, Options $options): void
    {
        $request = Yii::$app->getRequest();

        // Skip if the current request is made via console.
        if ($request->isConsoleRequest) {
            return;
        }

        $requestMethod = $request->getMethod();

        $requestData = [
            'url' => $request->getUrl(),
            'method' => $requestMethod,
            'query_string' => $request->getQueryString(),
        ];

        // Process headers, cookies, etc. Done the same way as in RequestIntegration, but using Yii's stuff.
        /** @see \Sentry\Integration\RequestIntegration */
        if ($options->shouldSendDefaultPii()) {
            $headers = $request->getHeaders();
            if ($headers->has('REMOTE_ADDR')) {
                $requestData['env']['REMOTE_ADDR'] = $headers->get('REMOTE_ADDR');
            }

            $requestData['cookies'] = $request->getCookies();
            $requestData['headers'] = $headers->toArray();

            $userContext = $event->getUserContext();

            if (null === $userContext->getIpAddress() && $headers->has('REMOTE_ADDR')) {
                $userContext->setIpAddress($headers->get('REMOTE_ADDR'));
            }
        } else {
            $requestData['headers'] = $this->processHeaders($request->getHeaders()->toArray());
        }

        // Process request body
        if (in_array($requestMethod, $this->httpMethodsWithRequestBody, true)) {
            $rawBody = $request->getRawBody();
            if ($rawBody !== '') {
                $bodyParams = $request->getBodyParams();

                $actionId = Yii::$app->requestedAction->getUniqueId();
                if (!$options->shouldSendDefaultPii() && isset($this->piiBodyFields[$actionId])) {
                    $requestData['data'] = 'Not available due to PII. See "bodyParams" in Additional data block.';

                    $this->removeKeysFromArrayRecursively($bodyParams, $this->piiBodyFields[$actionId]);
                } else {
                    $requestData['data'] = $rawBody;
                }

                $event->setExtra([
                    'decodedParams' => $bodyParams,
                ]);
            }
        }

        // Set!
        $event->setRequest($requestData);
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

            // Check fo PII in headers
            if (in_array(strtolower((string)$header), $this->piiHeaders, true)) {
                $result[$header] = $this->piiReplaceText;
            } else {
                $result[$header] = $value;
            }
        }

        return $result;
    }

    protected function removeKeysFromArrayRecursively(array &$array, array $keysToRemove): void
    {
        foreach ($keysToRemove as $key => $value) {
            if (is_string($key) && is_array($value)) {
                $this->removeKeysFromArrayRecursively($array[$key], $value);
            } else {
                if (isset($array[$value])) {
                    $array[$value] = $this->piiReplaceText;
                }
            }
        }
    }
}
