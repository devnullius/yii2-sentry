<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use UnexpectedValueException;
use yii\base\BaseObject;
use yii\queue\serializers\SerializerInterface;

/**
 * Stores jobs in a form no worker can read back, like a job whose class was renamed after it was queued.
 */
final class UnreadableJobSerializer extends BaseObject implements SerializerInterface
{
    public function serialize($job)
    {
        return 'unreadable';
    }

    public function unserialize($serialized)
    {
        throw new UnexpectedValueException('The job could not be read.');
    }
}
