<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A job that keeps the CPU busy for a moment, long enough for a profiler to take samples of it.
 */
final class BusyJob extends BaseObject implements JobInterface
{
    /**
     * @var float
     */
    public $seconds = 0.1;

    public function execute($queue)
    {
        $end = microtime(true) + $this->seconds;
        while (microtime(true) < $end) {
            // Busy, unlike sleep(), which a profiler samples only once it returns.
        }
    }
}
