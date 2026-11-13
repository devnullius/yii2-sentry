<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Worker;

use devnullius\yii2\sentry\tests\Fixtures\Jobs\ChargeJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\CrashingJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\GreetJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\SlowJob;
use devnullius\yii2\sentry\tests\Support\ConsoleProcessTestCase;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use yii\queue\file\Queue;
use yii\queue\JobInterface;

/**
 * End-to-end: a real yii2-queue worker ("yii queue/run", tests/Fixtures/Console/yii) running every job in a child
 * process of its own, as it does by default. The worker and its child processes all record what the SDK would send.
 */
final class QueueWorkerTest extends ConsoleProcessTestCase
{
    public function testAFailedJobIsReportedFromItsOwnProcess(): void
    {
        $this->push(new ChargeJob(['orderId' => 1001, 'email' => 'bob@example.com']));

        $worker = $this->runWorker();

        $event = $this->singleEvent($worker);
        $this->assertSame('RuntimeException', $event['exception']['values'][0]['type'] ?? null);
        $this->assertSame(ChargeJob::class, $event['transaction'] ?? null);
        $job = $event['contexts']['job'] ?? [];
        $this->assertSame([ChargeJob::class, '1', 1], [$job['class'] ?? null, $job['id'] ?? null, $job['attempt'] ?? null]);
        $this->assertSame(1001, $job['data']['orderId'] ?? null);
        $this->assertSame('[Filtered PII]', $job['data']['email'] ?? null);
    }

    public function testAJobThatEndsItsProcessIsReportedByTheWorkerWithoutWhatTheProcessPrinted(): void
    {
        $this->push(new CrashingJob(['email' => 'carol@example.com']));

        $worker = $this->runWorker();

        $event = $this->singleEvent($worker);
        $exception = $event['exception']['values'][0] ?? [];
        $this->assertSame(ProcessFailedException::class, $exception['type'] ?? null);
        $this->assertStringContainsString('Exit Code: 5', $exception['value'] ?? '');
        $this->assertSame(CrashingJob::class, $event['transaction'] ?? null);
        $this->assertSame(CrashingJob::class, $event['contexts']['job']['class'] ?? null);
        // The worker's code is the same for every job that crashes: grouped by job instead.
        $this->assertSame(['{{ default }}', CrashingJob::class], $event['fingerprint'] ?? null);
        $this->assertSame('[Filtered PII]', $event['contexts']['job']['data']['email'] ?? null);
        $sent = (string)json_encode($worker['payloads']);
        $this->assertStringNotContainsString('4111111111111111', $sent);
        $this->assertStringNotContainsString('sk_live_123', $sent);
        // Nor the job as yii2-queue stores it, passed from function to function in the worker.
        $this->assertStringNotContainsString('carol@example.com', $sent);
    }

    public function testAJobThatRunsPastItsTimeToReserveIsReported(): void
    {
        $this->push(new SlowJob(['seconds' => 5]), 1);

        $worker = $this->runWorker();

        $event = $this->singleEvent($worker);
        $this->assertSame(ProcessTimedOutException::class, $event['exception']['values'][0]['type'] ?? null);
        $this->assertSame(SlowJob::class, $event['contexts']['job']['class'] ?? null);
    }

    public function testEveryJobIsATransactionAndTheWorkerIsNot(): void
    {
        $this->push(new GreetJob(['email' => 'ann@example.com']));
        $this->push(new ChargeJob());

        $worker = $this->runWorker('tracing');

        $this->assertSame([GreetJob::class, ChargeJob::class], array_column($worker['transactions'], 'transaction'), $worker['log']);
        $this->assertSame(['queue.process', 'queue.process'], array_map(static function (array $transaction): ?string {
            return $transaction['contexts']['trace']['op'] ?? null;
        }, $worker['transactions']));
        $this->assertSame(['ok', 'internal_error'], array_map(static function (array $transaction): ?string {
            return $transaction['contexts']['trace']['status'] ?? null;
        }, $worker['transactions']));
    }

    public function testJobsThatEndTheirProcessAreNotReportedInOneTrace(): void
    {
        $this->push(new CrashingJob());
        $this->push(new CrashingJob());

        $worker = $this->runWorker();

        $this->assertCount(2, $worker['events'], $worker['log']);
        [$first, $second] = $worker['events'];
        $this->assertNotSame($first['contexts']['trace']['trace_id'] ?? null, $second['contexts']['trace']['trace_id'] ?? null);
    }

    public function testTheStoredJobIsHiddenWhenItDiffersFromWhatTheJobIsStoredAsNow(): void
    {
        // Queued before its class had the "note" property, so stored without it.
        $id = $this->push(new CrashingJob(['email' => 'carol@example.com']));
        $file = $this->dir . "/queue/job$id.data";
        $stored = (string)file_get_contents($file);
        $older = (string)preg_replace('/^(O:\d+:"[^"]+"):2:\{(.*)s:4:"note";s:0:"";\}$/s', '$1:1:{$2}', $stored);
        $this->assertNotSame($stored, $older);
        file_put_contents($file, $older);
        // Holding bytes that are not UTF-8, which the SDK converts.
        $this->push(new CrashingJob(['email' => 'carol@example.com', 'note' => "\xff\xfe\x81"]));

        $worker = $this->runWorker();

        $this->assertCount(2, $worker['events'], $worker['log']);
        $this->assertStringNotContainsString('carol@example.com', (string)json_encode($worker['payloads']));
    }

    public function testAProcessThatEndsAJobWithoutRunningItIsNotTraced(): void
    {
        // yii2-queue runs no attempt past the job's attempts, e.g. one reserved again after the worker running it died.
        $process = $this->runCommand(['queue/exec', '7', '300', '2', '0'], serialize(new GreetJob()), 'tracing');

        $this->assertSame(0, $process['exitCode'], $process['log']);
        $this->assertSame([], $process['transactions'], $process['log']);
    }

    /**
     * Queues a job for the worker, the way an application does.
     *
     * @return string the job's ID
     */
    private function push(JobInterface $job, ?int $ttr = null): string
    {
        $queue = new Queue(['path' => $this->dir . '/queue']);
        if ($ttr !== null) {
            $queue->ttr($ttr);
        }

        return (string)$queue->push($job);
    }

    /**
     * Runs "yii queue/run": the worker handles every job queued, each in a child process, and stops.
     *
     * @return array{exitCode: int, log: string, payloads: list<array<string, mixed>>, events: list<array<string, mixed>>,
     *               transactions: list<array<string, mixed>>, checkIns: list<array<string, mixed>>}
     */
    private function runWorker(string $scenario = 'default'): array
    {
        return $this->runCommand(['queue/run'], '', $scenario);
    }

    /**
     * @param array{log: string, events: list<array<string, mixed>>} $worker
     *
     * @return array<string, mixed>
     */
    private function singleEvent(array $worker): array
    {
        $this->assertCount(1, $worker['events'], "Expected exactly one event to be sent to Sentry.\n" . $worker['log']);

        return $worker['events'][0];
    }
}
