<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

/**
 * Leaves out the job data that texts about failed yii2-queue jobs may hold: everything a job's process printed, when a
 * worker ran the job in a process of its own and that process failed (Symfony Process' ProcessFailedException), and a
 * dump of a stored job that can't be read as a job, e.g. because its class was renamed after it was queued
 * (yii2-queue's InvalidJobException).
 *
 * @internal
 */
final class FailedJobText
{
    /**
     * Where ProcessFailedException starts what the process printed.
     */
    private const OUTPUT = "\n\nOutput:\n================\n";
    /**
     * Where InvalidJobException starts the dump of what it read.
     */
    private const NOT_A_JOB = 'Job must be a JobInterface instance instead of ';

    /**
     * @param string $text an exception's message, or the line yii2-queue's LogBehavior logs about a failed job, which
     *                     goes on with the exception's stack trace: that is left out with the data before it
     */
    public static function withoutJobData(string $text): string
    {
        $output = strpos($text, self::OUTPUT);
        if ($output !== false) {
            $text = substr($text, 0, $output) . "\n\nOutput: [not sent]";
        }

        $dump = strpos($text, self::NOT_A_JOB);
        if ($dump !== false) {
            $start = $dump + strlen(self::NOT_A_JOB);
            $text = substr($text, 0, $start) . self::typeOfDump(substr($text, $start)) . '.';
        }

        return $text;
    }

    /**
     * @param string $dump how Yii's VarDumper shows a value, e.g. "app\jobs\SendJob#1\n(\n    [email] => ..."
     *
     * @return string the value's class, without its data
     */
    private static function typeOfDump(string $dump): string
    {
        if (preg_match('/^([\w\\\\]+)#\d+/', $dump, $object) !== 1) {
            return 'a value that is not an object';
        }

        // PHP's stand-in for an object of a class that can't be loaded: also the class it was stored as.
        if (preg_match("/\\[__PHP_Incomplete_Class_Name\\] => '((?:[^'\\\\]|\\\\.)*)'/", $dump, $stored) === 1) {
            return $object[1] . ' (' . stripslashes($stored[1]) . ')';
        }

        return $object[1];
    }
}
