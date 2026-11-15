<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use yii\queue\serializers\PhpSerializer;

/**
 * Stores jobs cut short, like a message truncated by a database column that is too small.
 */
final class CutShortPhpSerializer extends PhpSerializer
{
    public function serialize($job)
    {
        return substr(parent::serialize($job), 0, -3);
    }
}
