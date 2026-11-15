<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use devnullius\yii2\sentry\Component;

/**
 * Records "finish the response" calls instead of calling fastcgi_finish_request(), which only exists under PHP-FPM.
 */
final class FinishRecordingComponent extends Component
{
    /**
     * @var int
     */
    public $finishedResponses = 0;

    protected function finishResponse(): void
    {
        $this->finishedResponses++;
    }
}
