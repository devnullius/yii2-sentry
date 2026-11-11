<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use devnullius\yii2\sentry\TraceableJobInterface;
use devnullius\yii2\sentry\TraceableJobTrait;
use RuntimeException;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A job that carries the trace of the code that queued it.
 */
final class TracedJob extends BaseObject implements JobInterface, TraceableJobInterface
{
    use TraceableJobTrait;

    /**
     * @var bool
     */
    public $fail = false;

    public function execute($queue)
    {
        if ($this->fail) {
            throw new RuntimeException('Sending failed');
        }
    }
}
