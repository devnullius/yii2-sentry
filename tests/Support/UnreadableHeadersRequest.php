<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use RuntimeException;
use yii\web\Request;

/**
 * A request whose headers can't be read, for failures while an event is being reported.
 */
final class UnreadableHeadersRequest extends Request
{
    public function getHeaders()
    {
        throw new RuntimeException('The headers are unavailable.');
    }
}
