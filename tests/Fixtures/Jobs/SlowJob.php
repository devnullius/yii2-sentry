<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A job that takes a while.
 */
final class SlowJob extends BaseObject implements JobInterface
{
    /**
     * @var int
     */
    public $seconds = 1;

    public function execute($queue)
    {
        sleep($this->seconds);
    }
}
