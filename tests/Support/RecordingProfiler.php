<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use Sentry\Profiling\Profile;
use Sentry\Profiling\ProfilerInterface;

/**
 * A profiler that only records when it is started and stopped, set with the SDK's `profiler_factory` option
 * (sentry/sentry 4.35 or newer). All of them write to one log, so it shows the order across profilers.
 */
final class RecordingProfiler implements ProfilerInterface
{
    /**
     * @var list<string> e.g. "1 start": the profiler's number, in the order they were created, and what happened
     */
    public static $log = [];

    /**
     * @var int
     */
    private static $created = 0;

    /**
     * @var int
     */
    private $number;

    public function __construct()
    {
        $this->number = ++self::$created;
    }

    public function start(): void
    {
        self::$log[] = $this->number . ' start';
    }

    public function stop(): void
    {
        self::$log[] = $this->number . ' stop';
    }

    public function getProfile(): ?Profile
    {
        return null;
    }
}
