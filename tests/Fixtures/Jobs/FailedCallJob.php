<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use devnullius\yii2\sentry\tests\Support\FakeHttpTransport;
use yii\base\BaseObject;
use yii\httpclient\Client;
use yii\httpclient\Exception;
use yii\queue\JobInterface;

/**
 * A job whose HTTP call gets no response; the job handles that and succeeds.
 */
final class FailedCallJob extends BaseObject implements JobInterface
{
    public function execute($queue)
    {
        $client = new Client(['transport' => ['class' => FakeHttpTransport::class, 'failure' => 'Connection refused']]);
        try {
            $client->get('https://api.example.com/v1/customers/ann@example.com/charge')->send();
        } catch (Exception $exception) {
            // Tried again later.
        }
    }
}
