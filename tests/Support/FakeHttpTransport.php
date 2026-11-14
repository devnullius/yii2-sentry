<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use Yii;
use yii\httpclient\Exception;
use yii\httpclient\Request;
use yii\httpclient\Transport;

/**
 * Answers yii2-httpclient requests without a network, going through the same steps and log line as its transports.
 */
final class FakeHttpTransport extends Transport
{
    /**
     * @var int
     */
    public $statusCode = 201;
    /**
     * @var string|null when set, the call fails like a connection error, with this message
     */
    public $failure;
    /**
     * @var list<Request> the requests as they were sent
     */
    public $sent = [];

    public function send($request)
    {
        $request->beforeSend();
        $request->prepare();

        // The same line yii\httpclient\StreamTransport::send() logs, with every header and the body.
        Yii::info(
            $request->client->createRequestLogToken($request->getMethod(), $request->getFullUrl(), $request->composeHeaderLines(), (string)$request->getContent()),
            'yii\httpclient\StreamTransport::send'
        );
        $this->sent[] = $request;

        if ($this->failure !== null) {
            throw new Exception($this->failure);
        }

        $response = $request->client->createResponse('{}', ["HTTP/1.1 {$this->statusCode} Status"]);
        $request->afterSend($response);

        return $response;
    }
}
