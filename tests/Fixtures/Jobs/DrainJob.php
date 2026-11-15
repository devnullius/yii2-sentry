<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use RuntimeException;
use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A job that runs the jobs of another queue in its own process, as a worker would, and then fails.
 */
final class DrainJob extends BaseObject implements JobInterface
{
    public function execute($queue)
    {
        Yii::$app->get('otherQueue')->run(false);

        throw new RuntimeException('Draining failed');
    }
}
