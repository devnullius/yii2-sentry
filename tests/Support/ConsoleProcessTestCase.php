<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use PHPUnit\Framework\TestCase;
use yii\helpers\FileHelper;

/**
 * Base for end-to-end tests that run the console test application (tests/Fixtures/Console/yii) in processes of its
 * own, as they run in production: with real exits and shutdown functions. Every process records what the SDK would
 * send to a file.
 */
abstract class ConsoleProcessTestCase extends TestCase
{
    /**
     * The exit code of a command that a fatal error ended: 1, which Yii's error handler exits with, except on PHP 7.4,
     * where the process keeps the fatal error's 255.
     */
    protected const FATAL_ERROR_EXIT_CODE = PHP_VERSION_ID < 80000 ? 255 : 1;

    /**
     * @var string the test's directory, with the "queue" directory of the application's file queue
     */
    protected $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/yii2-sentry-console-' . bin2hex(random_bytes(4));
        FileHelper::createDirectory($this->dir . '/queue');
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->dir);
    }

    /**
     * Runs a console command of the test application.
     *
     * @param list<string> $arguments
     * @param string $scenario the application's setup, see tests/Fixtures/Console/config.php
     *
     * @return array{exitCode: int, log: string, payloads: list<array<string, mixed>>, events: list<array<string, mixed>>,
     *               transactions: list<array<string, mixed>>, checkIns: list<array<string, mixed>>}
     */
    protected function runCommand(array $arguments, string $input, string $scenario): array
    {
        $outFile = $this->dir . '/sentry.jsonl';
        $logFile = $this->dir . '/output.log';
        $env = getenv();
        $env['SENTRY_TEST_SCENARIO'] = $scenario;
        $env['SENTRY_TEST_OUT_FILE'] = $outFile;
        $env['SENTRY_TEST_QUEUE_PATH'] = $this->dir . '/queue';

        $process = proc_open(
            array_merge([PHP_BINARY, dirname(__DIR__) . '/Fixtures/Console/yii'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
            $pipes,
            null,
            $env
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $exitCode = proc_close($process);

        $payloads = RecordingTransport::readFile($outFile);

        return [
            'exitCode' => $exitCode,
            'log' => "Command output:\n" . (string)@file_get_contents($logFile),
            'payloads' => $payloads,
            'events' => RecordingTransport::onlyType($payloads, 'event'),
            'transactions' => RecordingTransport::onlyType($payloads, 'transaction'),
            'checkIns' => RecordingTransport::onlyType($payloads, 'check_in'),
        ];
    }
}
