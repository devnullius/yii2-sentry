<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use yii\queue\serializers\PhpSerializer;

/**
 * Reads jobs back under a class name that doesn't exist, like a job whose class was renamed by a deploy while the job
 * was waiting in the queue.
 */
final class RenamedClassSerializer extends PhpSerializer
{
    public function unserialize($serialized)
    {
        return unserialize((string)preg_replace_callback('/^O:\d+:"([^"]+)"/', static function (array $match): string {
            $renamed = $match[1] . 'Old';

            return 'O:' . strlen($renamed) . ':"' . $renamed . '"';
        }, $serialized));
    }
}
