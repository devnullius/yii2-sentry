<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\Component;
use devnullius\yii2\sentry\LogTarget;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\BusyJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\ChargeJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\DrainJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\FailedCallJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\GreetJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\LowStockJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\QueryJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\ReleaseFailsJob;
use devnullius\yii2\sentry\tests\Fixtures\Jobs\TracedJob;
use devnullius\yii2\sentry\tests\Support\CutShortJsonSerializer;
use devnullius\yii2\sentry\tests\Support\CutShortPhpSerializer;
use devnullius\yii2\sentry\tests\Support\Identity;
use devnullius\yii2\sentry\tests\Support\RecordingProfiler;
use devnullius\yii2\sentry\tests\Support\RecordingTransport;
use devnullius\yii2\sentry\tests\Support\RenamedClassSerializer;
use devnullius\yii2\sentry\tests\Support\TestCase;
use devnullius\yii2\sentry\tests\Support\UnreadableJobSerializer;
use Sentry\Options;
use Sentry\Profiling\ProfilerInterface;
use Sentry\Profiling\Profiles;
use Sentry\Tracing\Transaction;
use UnexpectedValueException;
use Yii;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApplication;
use yii\db\Connection;
use yii\db\Exception as DbException;
use yii\helpers\FileHelper;
use yii\mutex\FileMutex;
use yii\queue\db\Queue as DbQueue;
use yii\queue\file\Queue as FileQueue;
use yii\queue\InvalidJobException;
use yii\queue\LogBehavior;
use yii\queue\PushEvent;
use yii\queue\Queue;
use yii\queue\sync\Queue as SyncQueue;
use yii\web\Application;

/**
 * yii2-queue jobs: failed jobs as error events, a scope of their own, breadcrumbs, tracing and trace linking.
 *
 * The sync queue runs jobs inside the request or command that queued them; the file queue, run with
 * "queue/run --isolate=0", is a worker running jobs in its own process. tests/Worker runs a worker that starts a
 * process for every job, as yii2-queue does by default.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class QueueTest extends TestCase
{
    /**
     * @var string
     */
    private $queuePath = '';

    protected function tearDown(): void
    {
        if ($this->queuePath !== '') {
            FileHelper::removeDirectory($this->queuePath);
        }
        parent::tearDown();
    }

    // --- Failed jobs ----------------------------------------------------------------------------------------------

    public function testAFailedJobIsReportedAsItsException(): void
    {
        $app = $this->queueApp();

        $app->queue->push(new ChargeJob(['orderId' => 1001]));
        $app->queue->run();

        $event = $this->singleEvent();
        $exception = $event['exception']['values'][0] ?? [];
        $this->assertSame('RuntimeException', $exception['type'] ?? null);
        $this->assertSame('Payment declined', $exception['value'] ?? null);
        $this->assertSame('auto.queue.yii2', $exception['mechanism']['type'] ?? null);
        $this->assertFalse($exception['mechanism']['handled'] ?? null, 'Nothing in the job caught it.');
        $this->assertSame(ChargeJob::class, $event['transaction'] ?? null);
        $this->assertSame('no', $event['tags']['job.retry'] ?? null);
        $this->assertSame([
            'class' => ChargeJob::class,
            'id' => '1',
            'attempt' => 1,
            'queue' => 'queue',
            'ttr' => 300,
            'retry' => false,
        ], array_diff_key($event['contexts']['job'] ?? [], ['data' => true]));
        // What the job logged before it failed.
        $this->assertSame(['Charging order 1001'], array_column($this->breadcrumbs($event, 'app'), 'message'));
    }

    public function testTheJobsDataIsSentWithSecretsAndPersonalDataHidden(): void
    {
        // PHP's default without a php.ini (e.g. in Docker images): stack traces hold the arguments of every call.
        ini_set('zend.exception_ignore_args', '0');
        $app = $this->queueApp();

        $app->queue->push(new ChargeJob([
            'orderId' => 1001,
            'email' => 'bob@example.com',
            'cardNumber' => '4111111111111111',
            'customer' => Identity::findIdentity(42),
            'items' => [['sku' => 'A-1', 'quantity' => 2]],
        ]));
        $app->queue->run();

        $event = $this->singleEvent();
        $this->assertSame([
            'orderId' => 1001,
            'email' => '[Filtered PII]',
            'cardNumber' => '[Filtered PII]',
            // Objects are named, not copied.
            'customer' => 'Object ' . Identity::class . '(#42)',
            'items' => [['sku' => 'A-1', 'quantity' => 2]],
        ], $event['contexts']['job']['data'] ?? null);
        // Nor anywhere else, e.g. the job as yii2-queue stores it, passed from function to function.
        $sent = (string)json_encode($event);
        $this->assertStringNotContainsString('bob@example.com', $sent);
        $this->assertStringNotContainsString('4111111111111111', $sent);
    }

    public function testALongListInTheJobsDataIsShortened(): void
    {
        $app = $this->queueApp();

        $app->queue->push(new ChargeJob(['items' => range(1, 500)]));
        $app->queue->run();

        $this->assertSame(range(1, 50), $this->singleEvent()['contexts']['job']['data']['items'] ?? null);
    }

    public function testPersonalDataInTheJobsDataIsShownWithSendDefaultPii(): void
    {
        $app = $this->queueApp(['sentrySettings' => ['send_default_pii' => true]]);

        $app->queue->push(new ChargeJob(['email' => 'bob@example.com', 'cardNumber' => '4111111111111111']));
        $app->queue->run();

        $data = $this->singleEvent()['contexts']['job']['data'] ?? [];
        $this->assertSame('bob@example.com', $data['email'] ?? null);
        $this->assertSame('[Filtered PII]', $data['cardNumber'] ?? null, 'Secrets stay hidden.');
    }

    public function testTheJobsDataCanBeLeftOut(): void
    {
        $app = $this->queueApp(['queue' => ['jobData' => false]]);

        $app->queue->push(new ChargeJob(['orderId' => 1001]));
        $app->queue->run();

        $job = $this->singleEvent()['contexts']['job'] ?? [];
        $this->assertSame(ChargeJob::class, $job['class'] ?? null);
        $this->assertArrayNotHasKey('data', $job);
    }

    public function testAFailedAttemptThatWillBeRetriedIsReportedToo(): void
    {
        $app = $this->queueApp([], ['attempts' => 3]);

        $app->queue->push(new ChargeJob());
        $app->queue->run();

        $event = $this->singleEvent();
        $this->assertSame('yes', $event['tags']['job.retry'] ?? null);
        $this->assertTrue($event['contexts']['job']['retry'] ?? null);
    }

    public function testRetriedAttemptsCanBeLeftOut(): void
    {
        $app = $this->queueApp(['queue' => ['retries' => false]], ['attempts' => 3]);
        $app->queue->push(new ChargeJob());
        $app->queue->run();

        $this->assertSame([], $this->transport->events(), 'The first of three attempts failed: not reported.');

        // The only attempt is the last one.
        $app = $this->queueApp(['queue' => ['retries' => false]]);
        $app->queue->push(new ChargeJob());
        $app->queue->run();

        $this->assertSame('no', $this->singleEvent()['tags']['job.retry'] ?? null);
    }

    public function testLogBehaviorsLineAboutTheFailureIsNotASecondEvent(): void
    {
        $app = $this->queueApp([], ['as log' => LogBehavior::class]);

        $app->queue->push(new ChargeJob());
        $app->queue->run();

        $event = $this->singleEvent();
        $this->assertSame('RuntimeException', $event['exception']['values'][0]['type'] ?? null);
        // Nor a breadcrumb repeating the exception.
        $this->assertSame([], $this->breadcrumbs($event, 'yii\queue\Queue'));
    }

    public function testReportingFailedJobsCanBeTurnedOff(): void
    {
        $app = $this->queueApp(['queue' => ['errors' => false]], ['as log' => LogBehavior::class]);

        $app->queue->push(new ChargeJob());
        $app->queue->run();

        // What LogBehavior logs is reported as before.
        $event = $this->singleEvent();
        $this->assertArrayNotHasKey('exception', $event);
        $this->assertStringContainsString('is finished with error: RuntimeException: Payment declined', $event['message'] ?? '');
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function logTargetModes(): array
    {
        return [
            'an event for every message' => [false],
            'one event for all messages' => [true],
        ];
    }

    /**
     * @dataProvider logTargetModes
     */
    public function testLogBehaviorsLineIsSentWithoutTheJobsDataWhenFailedJobsAreNotReported(bool $aggregate): void
    {
        $app = $this->queueApp(['queue' => ['errors' => false]], [
            'serializer' => RenamedClassSerializer::class,
            'as log' => LogBehavior::class,
        ], [
            'log' => ['targets' => ['sentry' => ['class' => LogTarget::class, 'aggregate' => $aggregate]]],
        ]);

        $app->queue->push(new ChargeJob(['email' => 'bob@example.com', 'cardNumber' => '4111111111111111']));
        $app->queue->run();

        $event = $this->singleEvent();
        $this->assertStringContainsString('instead of __PHP_Incomplete_Class (' . ChargeJob::class . 'Old).', $event['message'] ?? '');
        $sent = (string)json_encode($event);
        $this->assertStringNotContainsString('bob@example.com', $sent);
        $this->assertStringNotContainsString('4111111111111111', $sent);
    }

    public function testAJobThatCannotBeReadIsReported(): void
    {
        $app = $this->queueApp([], ['serializer' => UnreadableJobSerializer::class]);

        $app->queue->push(new ChargeJob());
        $app->queue->run();

        $event = $this->singleEvent();
        $this->assertSame([UnexpectedValueException::class, InvalidJobException::class], array_column($event['exception']['values'] ?? [], 'type'));
        $this->assertSame('unknown job', $event['transaction'] ?? null);
        $this->assertSame('1', $event['contexts']['job']['id'] ?? null);
        $this->assertNull($event['contexts']['job']['class'] ?? null);
    }

    public function testTheDataOfAJobWhoseClassWasRenamedIsNotSent(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        $app = $this->queueApp([], ['serializer' => RenamedClassSerializer::class]);

        $app->queue->push(new ChargeJob(['orderId' => 1001, 'email' => 'bob@example.com', 'cardNumber' => '4111111111111111']));
        $app->queue->run();

        $event = $this->singleEvent();
        // yii2-queue's message dumps what it read, with all its data: only the class is kept.
        $this->assertSame(
            'Job must be a JobInterface instance instead of __PHP_Incomplete_Class (' . ChargeJob::class . 'Old).',
            $event['exception']['values'][0]['value'] ?? null
        );
        $sent = (string)json_encode($event);
        $this->assertStringNotContainsString('bob@example.com', $sent);
        $this->assertStringNotContainsString('4111111111111111', $sent);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function serializersCuttingJobsShort(): array
    {
        return [
            'PHP' => [CutShortPhpSerializer::class],
            'JSON' => [CutShortJsonSerializer::class],
        ];
    }

    /**
     * @dataProvider serializersCuttingJobsShort
     */
    public function testAJobStoredCutShortIsReportedWithoutItsData(string $serializer): void
    {
        ini_set('zend.exception_ignore_args', '0');
        $app = $this->queueApp([], ['serializer' => $serializer]);

        $app->queue->push(new ChargeJob(['email' => 'bob@example.com', 'cardNumber' => '4111111111111111']));
        $app->queue->run();

        $event = $this->singleEvent();
        $this->assertContains(InvalidJobException::class, array_column($event['exception']['values'] ?? [], 'type'));
        // Nor the job as stored, passed to the functions that failed to read it.
        $sent = (string)json_encode($event);
        $this->assertStringNotContainsString('bob@example.com', $sent);
        $this->assertStringNotContainsString('4111111111111111', $sent);
    }

    public function testTheStoredJobIsHiddenInStackTracesOutsideAJobToo(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        $app = $this->workerApp([], [
            'db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:'],
            'mutex' => ['class' => FileMutex::class, 'mutexPath' => $this->queuePath() . '/mutex'],
            'queue' => ['class' => DbQueue::class],
        ]);
        $app->getDb()->createCommand()->createTable('queue', [
            'id' => 'pk',
            'channel' => 'string NOT NULL',
            'job' => 'blob NOT NULL',
            'pushed_at' => 'integer NOT NULL',
            'ttr' => 'integer NOT NULL',
            'delay' => 'integer NOT NULL DEFAULT 0',
            'priority' => 'integer NOT NULL DEFAULT 1024',
            'reserved_at' => 'integer',
            'attempt' => 'integer',
            'done_at' => 'integer',
        ])->execute();
        $app->queue->push(new ReleaseFailsJob(['email' => 'bob@example.com']));

        try {
            $this->runWorker($app);
            $this->fail('The worker should have failed to release the job.');
        } catch (DbException $exception) {
            // What Yii's error handler does with an exception nothing caught.
            Yii::error($exception, get_class($exception));
            Yii::getLogger()->flush(true);
        }

        // The driver passed the job, as stored, to the function releasing it.
        $this->assertStringNotContainsString('bob@example.com', (string)json_encode($this->singleEvent()));
    }

    /**
     * With the SDK's `data_collection` option, the SDK ignores `send_default_pii`: its `user_info` decides.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function dataCollectionPolicies(): array
    {
        return [
            'user info collected' => [['data_collection' => ['user_info' => true]], true],
            'user info not collected, despite send_default_pii' => [['send_default_pii' => true, 'data_collection' => ['user_info' => false]], false],
        ];
    }

    /**
     * @dataProvider dataCollectionPolicies
     *
     * @param array<string, mixed> $sentrySettings
     */
    public function testPersonalDataInTheJobsDataFollowsTheDataCollectionOption(array $sentrySettings, bool $shown): void
    {
        $app = $this->queueApp(['sentrySettings' => $sentrySettings]);

        $app->queue->push(new ChargeJob(['email' => 'bob@example.com', 'cardNumber' => '4111111111111111']));
        $app->queue->run();

        $data = $this->singleEvent()['contexts']['job']['data'] ?? [];
        $this->assertSame($shown ? 'bob@example.com' : '[Filtered PII]', $data['email'] ?? null);
        $this->assertSame('[Filtered PII]', $data['cardNumber'] ?? null, 'Secrets stay hidden.');
    }

    public function testTheJobsDataFollowsTheSdksDataCollectionOption(): void
    {
        $app = $this->queueApp(['sentrySettings' => ['data_collection' => ['queues' => false]]]);

        $app->queue->push(new ChargeJob(['orderId' => 1001]));
        $app->queue->run();

        $job = $this->singleEvent()['contexts']['job'] ?? [];
        $this->assertSame(ChargeJob::class, $job['class'] ?? null);
        $this->assertArrayNotHasKey('data', $job);
    }

    public function testErrorsAJobLogsCarryItsDetails(): void
    {
        $app = $this->queueApp();

        $app->queue->push(new LowStockJob(['sku' => 'A-1']));
        $app->queue->run();

        // Sent when the job ends, while its details still apply.
        $event = $this->singleEvent();
        $this->assertSame('Stock is low for A-1', $event['message'] ?? null);
        $this->assertSame(LowStockJob::class, $event['transaction'] ?? null);
        $job = $event['contexts']['job'] ?? [];
        $this->assertSame(LowStockJob::class, $job['class'] ?? null);
        $this->assertSame(['sku' => 'A-1'], $job['data'] ?? null);
        $this->assertArrayNotHasKey('retry', $job, 'The job did not fail.');
    }

    // --- A scope for every job ------------------------------------------------------------------------------------

    public function testAJobsEventsDoNotCarryWhatTheJobBeforeItDid(): void
    {
        $app = $this->workerApp();
        $app->queue->push(new GreetJob(['email' => 'ann@example.com']));
        $app->queue->push(new ChargeJob(['orderId' => 1001]));

        $this->runWorker($app);

        $event = $this->singleEvent();
        $this->assertSame(ChargeJob::class, $event['contexts']['job']['class'] ?? null);
        $messages = array_column($event['breadcrumbs']['values'] ?? [], 'message');
        $this->assertContains('Charging order 1001', $messages);
        $this->assertNotContains('Greeting ann@example.com', $messages);
    }

    public function testJobsAWorkerRunsDoNotShareATrace(): void
    {
        $app = $this->workerApp();
        $app->queue->push(new ChargeJob());
        $app->queue->push(new ChargeJob());

        $this->runWorker($app);

        $events = $this->transport->events();
        $this->assertCount(2, $events);
        $this->assertNotSame($events[0]['contexts']['trace']['trace_id'] ?? null, $events[1]['contexts']['trace']['trace_id'] ?? null);
    }

    public function testAJobRunInsideARequestKeepsWhatTheRequestDid(): void
    {
        $app = $this->createWebApp([], [], [
            'queue' => ['class' => SyncQueue::class],
            'log' => ['targets' => ['sentry' => ['class' => LogTarget::class]]],
        ]);
        $app->getLog();

        Yii::info('Checkout started', 'app');
        $app->queue->push(new ChargeJob());
        $app->queue->run();

        $this->assertContains('Checkout started', array_column($this->singleEvent()['breadcrumbs']['values'] ?? [], 'message'));
    }

    public function testWhatAJobRunInsideARequestDidStaysWithTheRequest(): void
    {
        $app = $this->createWebApp([], [], [
            'queue' => ['class' => SyncQueue::class],
            'log' => ['targets' => ['sentry' => ['class' => LogTarget::class]]],
        ]);
        $app->getLog();

        $app->queue->push(new GreetJob(['email' => 'ann@example.com']));
        $app->queue->run();
        Yii::getLogger()->flush(true);
        $app->get('sentry')->getHub()->captureMessage('a later problem');

        $event = $this->singleEvent();
        $this->assertContains('Greeting ann@example.com', array_column($event['breadcrumbs']['values'] ?? [], 'message'));
        // The job's own details end with it.
        $this->assertArrayNotHasKey('job', $event['contexts'] ?? []);
    }

    public function testAJobsCallsThatGotNoResponseDoNotShowInTheNextJobsEvents(): void
    {
        $app = $this->workerApp();
        $app->queue->push(new FailedCallJob());
        $app->queue->push(new ChargeJob());

        $this->runWorker($app);

        $this->assertSame([], $this->breadcrumbs($this->singleEvent(), 'http'));
    }

    public function testAJobThatRunsTheJobsOfAnotherQueueIsReportedAsItself(): void
    {
        $app = $this->workerApp(['sentrySettings' => ['traces_sample_rate' => 1.0]], [
            'otherQueue' => ['class' => FileQueue::class, 'path' => $this->queuePath() . '/other'],
        ]);
        $app->get('otherQueue')->push(new GreetJob(['email' => 'ann@example.com']));
        $app->queue->push(new DrainJob());

        $this->runWorker($app);

        $event = $this->singleEvent();
        $this->assertSame(DrainJob::class, $event['contexts']['job']['class'] ?? null);
        $this->assertArrayNotHasKey('fingerprint', $event, 'Not reported as a job that failed in a process of its own.');
        $transactions = $this->transactions();
        $this->assertSame([DrainJob::class], array_column($transactions, 'transaction'));
        $this->assertSame('internal_error', $transactions[0]['contexts']['trace']['status'] ?? null);
        // The other queue's job ran inside it.
        $this->assertSame([GreetJob::class], array_column($this->spans($transactions[0], 'queue.process'), 'description'));
    }

    // --- Breadcrumbs ----------------------------------------------------------------------------------------------

    public function testQueuingAJobIsABreadcrumb(): void
    {
        $app = $this->queueApp([], ['as log' => LogBehavior::class]);

        $app->queue->delay(60)->push(new GreetJob());
        Yii::getLogger()->flush(true);
        $app->get('sentry')->getHub()->captureMessage('after queuing');

        $event = $this->singleEvent();
        $this->assertSame([[
            'category' => 'queue',
            'message' => 'Queued ' . GreetJob::class,
            'data' => ['messaging.destination.name' => 'queue', 'messaging.message.id' => '1', 'delay' => 60],
        ]], array_map(static function (array $breadcrumb): array {
            return array_intersect_key($breadcrumb, ['category' => true, 'message' => true, 'data' => true]);
        }, $this->breadcrumbs($event, 'queue')));
        // It takes the place of LogBehavior's line.
        $this->assertSame([], $this->breadcrumbs($event, 'yii\queue\Queue'));
    }

    public function testQueueBreadcrumbsCanBeTurnedOff(): void
    {
        $app = $this->queueApp(['queue' => ['breadcrumbs' => false]]);

        $app->queue->push(new GreetJob());
        $app->get('sentry')->getHub()->captureMessage('after queuing');

        $this->assertSame([], $this->breadcrumbs($this->singleEvent(), 'queue'));
    }

    // --- Tracing --------------------------------------------------------------------------------------------------

    public function testEachJobAWorkerRunsIsATransactionOfItsOwn(): void
    {
        $app = $this->workerApp(['sentrySettings' => ['traces_sample_rate' => 1.0]]);
        $app->queue->push(new GreetJob());
        $app->queue->push(new ChargeJob());

        $this->runWorker($app);

        // The worker is not one: it runs for as long as it waits for jobs.
        $transactions = $this->transactions();
        $this->assertSame([GreetJob::class, ChargeJob::class], array_column($transactions, 'transaction'));
        [$greet, $charge] = $transactions;
        $this->assertSame('queue.process', $greet['contexts']['trace']['op'] ?? null);
        $this->assertSame('task', $greet['transaction_info']['source'] ?? null);
        $this->assertSame('ok', $greet['contexts']['trace']['status'] ?? null);
        $this->assertSame([
            'messaging.system' => 'file',
            'messaging.destination.name' => 'queue',
            'messaging.message.id' => '1',
            'messaging.message.retry.count' => 0,
        ], array_intersect_key($greet['contexts']['trace']['data'] ?? [], array_flip([
            'messaging.system',
            'messaging.destination.name',
            'messaging.message.id',
            'messaging.message.retry.count',
        ])));
        $this->assertSame('internal_error', $charge['contexts']['trace']['status'] ?? null);
        // The failure is linked to its job's transaction.
        $this->assertSame($charge['contexts']['trace']['trace_id'] ?? null, $this->singleEvent()['contexts']['trace']['trace_id'] ?? null);
    }

    public function testTheCommandOfAWorkerIsFinishedWithoutBeingSent(): void
    {
        $app = $this->workerApp(['sentrySettings' => ['traces_sample_rate' => 1.0]]);
        $command = $app->get('sentry')->getHub()->getSpan();
        $app->queue->push(new GreetJob());

        $this->runWorker($app);

        $this->assertInstanceOf(Transaction::class, $command);
        $this->assertSame('console.command', $command->getOp());
        // Left unfinished, its profilers would keep running for as long as the worker runs.
        $this->assertNotNull($command->getEndTimestamp());
        $this->assertSame([GreetJob::class], array_column($this->transactions(), 'transaction'));
    }

    public function testTheProfilerOfAWorkersCommandStopsBeforeItsJobsAreProfiled(): void
    {
        if (!interface_exists(ProfilerInterface::class)) {
            $this->markTestSkipped('The profiler_factory option needs sentry/sentry 4.35 or newer.');
        }

        $app = $this->workerApp(['sentrySettings' => [
            'traces_sample_rate' => 1.0,
            'profiles_sample_rate' => 1.0,
            'profiler_factory' => static function (): RecordingProfiler {
                return new RecordingProfiler();
            },
        ]]);
        $app->queue->push(new GreetJob());

        $this->runWorker($app);

        // 1 profiles the command, 2 the job. Left running, 1 would sample for as long as the worker runs.
        $this->assertSame(['1 start', '1 stop', '2 start', '2 stop'], RecordingProfiler::$log);
    }

    /**
     * @requires extension excimer
     */
    public function testContinuousProfilingStopsOnceTheJobsOfAWorkerAreDone(): void
    {
        if (!method_exists(Options::class, 'getProfileLifecycle')) {
            $this->markTestSkipped('Continuous profiling needs sentry/sentry 4.36 or newer.');
        }

        $app = $this->workerApp(['sentrySettings' => [
            'traces_sample_rate' => 1.0,
            'profile_session_sample_rate' => 1.0,
            'profile_lifecycle' => 'trace',
        ]]);
        $app->queue->push(new BusyJob());

        $this->runWorker($app);

        // Waiting for the worker's command to end, the profiler would sample for as long as the worker runs, also
        // while it waits for jobs, and send what it sampled every minute.
        $this->assertFalse(Profiles::getInstance()->continuousProfiler()->isRunning());
        [$busy] = $this->transactions();
        $profilerId = $busy['contexts']['profile']['profiler_id'] ?? null;
        $this->assertNotNull($profilerId, 'The job is linked to what was sampled during it.');
        $chunks = RecordingTransport::onlyType($this->transport->payloads, 'profile_chunk');
        $this->assertContains($profilerId, array_column($chunks, 'profiler_id'), 'What was sampled during the job is sent once it is done.');
    }

    public function testAJobsQueriesAreSpansOfItsOwnTransaction(): void
    {
        $app = $this->workerApp(['sentrySettings' => ['traces_sample_rate' => 1.0]], [
            'db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:'],
        ]);
        $app->queue->push(new QueryJob());
        $app->queue->push(new GreetJob());

        $this->runWorker($app);

        [$query, $greet] = $this->transactions();
        $this->assertSame(['SELECT ? AS email'], array_column($this->spans($query, 'db.sql.query'), 'description'));
        $this->assertSame([], $this->spans($greet, 'db.sql.query'));
    }

    public function testAJobRunInsideARequestIsASpanOfIt(): void
    {
        $app = $this->tracedWebApp(['queue' => ['class' => SyncQueue::class]]);

        $app->queue->push(new GreetJob());
        $app->queue->run();
        $transaction = $this->finishRequest($app, 200);

        $job = $this->spans($transaction, 'queue.process')[0] ?? [];
        $this->assertSame(GreetJob::class, $job['description'] ?? null);
        $this->assertSame('ok', $job['status'] ?? null);
    }

    public function testQueuingAJobIsASpan(): void
    {
        $app = $this->tracedWebApp(['queue' => ['class' => SyncQueue::class]]);

        $app->queue->push(new GreetJob());
        $transaction = $this->finishRequest($app, 200);

        $push = $this->spans($transaction, 'queue.publish')[0] ?? [];
        $this->assertSame(GreetJob::class, $push['description'] ?? null);
        $this->assertSame([
            'messaging.system' => 'sync',
            'messaging.destination.name' => 'queue',
            'messaging.message.id' => '1',
        ], array_intersect_key($push['data'] ?? [], array_flip(['messaging.system', 'messaging.destination.name', 'messaging.message.id'])));
    }

    public function testQueueTracingCanBeTurnedOff(): void
    {
        $app = $this->tracedWebApp(['queue' => ['class' => SyncQueue::class]], ['tracing' => ['queue' => false]]);
        $app->queue->push(new GreetJob());
        $app->queue->run();
        $transaction = $this->finishRequest($app, 200);

        $this->assertSame([], $this->spans($transaction, 'queue.publish'));
        $this->assertSame([], $this->spans($transaction, 'queue.process'));

        $app = $this->workerApp(['sentrySettings' => ['traces_sample_rate' => 1.0], 'tracing' => ['queue' => false]]);
        $app->queue->push(new GreetJob());
        $this->runWorker($app);

        // Nor is the worker traced as one command.
        $this->assertSame([], $this->transactions());
    }

    // --- Trace linking --------------------------------------------------------------------------------------------

    public function testATraceableJobContinuesTheTraceOfTheRequestThatQueuedIt(): void
    {
        $web = $this->tracedWebApp(['queue' => ['class' => FileQueue::class, 'path' => $this->queuePath()]]);
        $web->queue->push(new TracedJob());
        $request = $this->finishRequest($web, 200);
        $push = $this->spans($request, 'queue.publish')[0] ?? [];

        $worker = $this->workerApp(['sentrySettings' => ['traces_sample_rate' => 1.0]]);
        $this->runWorker($worker);

        $job = $this->transactions()[0] ?? [];
        $this->assertSame(TracedJob::class, $job['transaction'] ?? null);
        $this->assertSame($request['contexts']['trace']['trace_id'] ?? null, $job['contexts']['trace']['trace_id'] ?? null);
        $this->assertSame($push['span_id'] ?? null, $job['contexts']['trace']['parent_span_id'] ?? null, 'The job ran under the span that queued it.');
        // How long the job waited in the queue, in milliseconds.
        $latency = $job['contexts']['trace']['data']['messaging.message.receive.latency'] ?? null;
        $this->assertIsNumeric($latency);
        $this->assertGreaterThanOrEqual(0, $latency);
        $this->assertLessThan(60000, $latency);
    }

    public function testWithoutTracingTheErrorsOfATraceableJobJoinTheTraceThatQueuedIt(): void
    {
        $web = $this->createWebApp([], [], ['queue' => ['class' => FileQueue::class, 'path' => $this->queuePath()]]);
        $web->queue->push(new TracedJob(['fail' => true]));
        $web->get('sentry')->getHub()->captureMessage('queued');
        $request = $this->singleEvent();

        $this->runWorker($this->workerApp());

        $this->assertSame($request['contexts']['trace']['trace_id'] ?? null, $this->singleEvent()['contexts']['trace']['trace_id'] ?? null);
    }

    public function testContinuingTheTraceThatQueuedAJobCanBeTurnedOff(): void
    {
        $web = $this->createWebApp([], [], ['queue' => ['class' => FileQueue::class, 'path' => $this->queuePath()]]);
        $web->queue->push(new TracedJob(['fail' => true]));
        $web->get('sentry')->getHub()->captureMessage('queued');
        $request = $this->singleEvent();

        $this->runWorker($this->workerApp(['tracing' => ['continueTraces' => false]]));

        $this->assertNotSame($request['contexts']['trace']['trace_id'] ?? null, $this->singleEvent()['contexts']['trace']['trace_id'] ?? null);
    }

    public function testOnlyTraceableJobsCarryTheTrace(): void
    {
        $app = $this->workerApp();

        $traced = new TracedJob();
        $app->queue->push($traced);
        $app->queue->push(new GreetJob(['email' => 'ann@example.com']));

        $this->assertSame(['sentry-trace', 'baggage', 'queued_at', 'delay'], array_keys($traced->getSentryTrace() ?? []));
        // Other jobs are stored exactly as yii2-queue stores them.
        $this->assertSame(
            $app->queue->serializer->serialize(new GreetJob(['email' => 'ann@example.com'])),
            file_get_contents($this->queuePath() . '/job2.data')
        );
    }

    // --- Settings and safety --------------------------------------------------------------------------------------

    public function testAFailureWhileHandlingAQueueEventDoesNotBreakTheQueue(): void
    {
        $app = $this->queueApp();

        // An event yii2-queue never sends: without a queue, the handler fails.
        Event::trigger(SyncQueue::class, Queue::EVENT_AFTER_PUSH, new PushEvent());
        $warnings = array_filter(Yii::getLogger()->messages, static function (array $message): bool {
            return $message[2] === Component::class && strpos((string)$message[0], 'Unable to handle the "afterPush" event for Sentry') === 0;
        });
        $this->assertCount(1, $warnings);

        $app->queue->push(new ChargeJob());
        $app->queue->run();
        $this->assertSame('RuntimeException', $this->singleEvent()['exception']['values'][0]['type'] ?? null);
    }

    public function testUnknownQueueSettingsAreRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Unknown Sentry queue setting "retry".');

        $this->createConsoleApp(['queue' => ['retry' => false]]);
    }

    // --- Helpers --------------------------------------------------------------------------------------------------

    /**
     * A console application with a queue (the sync queue, unless $queue says otherwise) and LogTarget.
     *
     * @param array<string, mixed> $sentry
     * @param array<string, mixed> $queue
     * @param array<string, mixed> $components
     */
    private function queueApp(array $sentry = [], array $queue = [], array $components = []): ConsoleApplication
    {
        $app = $this->createConsoleApp($sentry, array_merge([
            'queue' => array_merge(['class' => SyncQueue::class], $queue),
            'log' => ['targets' => ['sentry' => ['class' => LogTarget::class]]],
        ], $components));
        $app->getLog();

        return $app;
    }

    /**
     * A console application whose file queue a worker runs, in this process.
     *
     * @param array<string, mixed> $sentry
     * @param array<string, mixed> $components
     */
    private function workerApp(array $sentry = [], array $components = []): ConsoleApplication
    {
        // The worker's command line: the component reads it while the application starts.
        $_SERVER['argv'] = ['yii', 'queue/run', '--isolate=0'];
        $app = $this->queueApp($sentry, ['class' => FileQueue::class, 'path' => $this->queuePath()], $components);
        // Adds the "queue" command, like listing the queue in the application's "bootstrap" does.
        $app->get('queue')->bootstrap($app);

        return $app;
    }

    /**
     * Runs the worker until no job is left, in this process.
     */
    private function runWorker(ConsoleApplication $app): void
    {
        $_SERVER['argv'] = ['yii', 'queue/run', '--isolate=0'];
        $app->run();
    }

    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $sentry
     */
    private function tracedWebApp(array $components, array $sentry = []): Application
    {
        $sentry['sentrySettings'] = array_merge(['traces_sample_rate' => 1.0], $sentry['sentrySettings'] ?? []);

        return $this->createWebApp($sentry, [], $components);
    }

    private function queuePath(): string
    {
        if ($this->queuePath === '') {
            $this->queuePath = sys_get_temp_dir() . '/yii2-sentry-queue-' . bin2hex(random_bytes(4));
        }

        return $this->queuePath;
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return list<array<string, mixed>> the event's breadcrumbs in the given category
     */
    private function breadcrumbs(array $event, string $category): array
    {
        return array_values(array_filter($event['breadcrumbs']['values'] ?? [], static function (array $breadcrumb) use ($category): bool {
            return ($breadcrumb['category'] ?? null) === $category;
        }));
    }
}
