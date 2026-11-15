<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A job that ends the process running it, after printing data that must not reach Sentry.
 */
final class CrashingJob extends BaseObject implements JobInterface
{
    /**
     * @var string
     */
    public $email = '';
    /**
     * @var string
     */
    public $note = '';

    public function execute($queue)
    {
        echo "Charging card 4111111111111111\n";
        fwrite(STDERR, "Token sk_live_123 rejected\n");

        exit(5);
    }
}
