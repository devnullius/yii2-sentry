<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A job that logs an error itself and succeeds.
 */
final class LowStockJob extends BaseObject implements JobInterface
{
    /**
     * @var string
     */
    public $sku = '';

    public function execute($queue)
    {
        Yii::error("Stock is low for {$this->sku}", 'app');
    }
}
