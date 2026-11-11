<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Jobs;

use RuntimeException;
use Yii;
use yii\base\BaseObject;
use yii\queue\JobInterface;

/**
 * A job that logs a line and fails, with the kind of data jobs carry: an ID, personal data, a secret, an object and a
 * list.
 */
final class ChargeJob extends BaseObject implements JobInterface
{
    /**
     * @var int
     */
    public $orderId = 0;
    /**
     * @var string
     */
    public $email = '';
    /**
     * @var string
     */
    public $cardNumber = '';
    /**
     * @var object|null
     */
    public $customer;
    /**
     * @var array<int, array<string, mixed>>
     */
    public $items = [];

    public function execute($queue)
    {
        Yii::info("Charging order {$this->orderId}", 'app');

        throw new RuntimeException('Payment declined');
    }
}
