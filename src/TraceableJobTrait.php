<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

/**
 * Implements [[TraceableJobInterface]]: the trace is kept in a public property, which every yii2-queue serializer
 * stores with the job.
 */
trait TraceableJobTrait
{
    /**
     * @var array<string, mixed>|null the trace of the code that queued the job: its `sentry-trace` and `baggage`
     * headers, and when it was queued. Set by the Sentry component.
     */
    public $sentryTrace;

    public function getSentryTrace(): ?array
    {
        return is_array($this->sentryTrace) ? $this->sentryTrace : null;
    }

    public function setSentryTrace(?array $trace): void
    {
        $this->sentryTrace = $trace;
    }
}
