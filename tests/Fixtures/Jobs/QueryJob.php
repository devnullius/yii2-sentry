<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A job that runs an SQL query.
 */
final class QueryJob extends BaseObject implements JobInterface
{
    public function execute($queue)
    {
        Yii::$app->getDb()->createCommand('SELECT :email AS email', [':email' => 'ann@example.com'])->queryScalar();
    }
}
