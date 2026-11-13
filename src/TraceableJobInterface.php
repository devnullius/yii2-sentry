<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

/**
 * A yii2-queue job that carries the trace of the code that queued it, so that Sentry shows the request (or command)
 * and the job as one trace, with the time the job waited in the queue, and the job's errors in that trace.
 *
 * yii2-queue stores nothing but the job itself, so the trace has to travel inside it. Implement this interface with
 * [[TraceableJobTrait]]:
 *
 * ```php
 * class SendInvoiceJob extends BaseObject implements JobInterface, TraceableJobInterface
 * {
 *     use TraceableJobTrait;
 * }
 * ```
 *
 * Jobs are reported and traced either way; this only links them to where they were queued.
 *
 * The trait adds a property to the job as it is stored: deploy the workers before, or together with, the code that
 * queues the job. A worker still running the class without the property can't read the job: with yii2-queue's
 * JsonSerializer when the job extends BaseObject, and otherwise on PHP 8.2 and later when deprecations are reported.
 * yii2-queue drops a job it can't read.
 */
interface TraceableJobInterface
{
    /**
     * @return array<string, mixed>|null the trace stored with [[setSentryTrace()]]
     */
    public function getSentryTrace(): ?array;

    /**
     * Called by the Sentry component when the job is queued.
     *
     * @param array<string, mixed>|null $trace
     */
    public function setSentryTrace(?array $trace): void;
}
