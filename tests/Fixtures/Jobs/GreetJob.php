<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A job that logs a line and succeeds.
 */
final class GreetJob extends BaseObject implements JobInterface
{
    /**
     * @var string
     */
    public $email = '';

    public function execute($queue)
    {
        Yii::info("Greeting {$this->email}", 'app');
    }
}
