<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A job that succeeds, but leaves the database queue unable to release it afterwards, like a database connection lost
 * during a long job.
 */
final class ReleaseFailsJob extends BaseObject implements JobInterface
{
    /**
     * @var string
     */
    public $email = '';

    public function execute($queue)
    {
        Yii::$app->getDb()->createCommand()->dropTable('queue')->execute();
    }
}
