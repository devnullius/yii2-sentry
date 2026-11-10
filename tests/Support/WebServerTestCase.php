<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

/**
 * Base for end-to-end tests: serves tests/Fixtures/WebApp with PHP's built-in server and sends real HTTP requests.
 *
 * The SAPI is "cli-server", so the SDK's own RequestIntegration is active as it is under PHP-FPM, and Yii's error
 * handler, sessions and shutdown sequence behave as in production. Each request names a scenario (see
 * tests/Fixtures/WebApp/config.php); what the SDK would send is recorded per request.
 */
abstract class WebServerTestCase extends \PHPUnit\Framework\TestCase
{
    protected const FORM = ['Content-Type: application/x-www-form-urlencoded'];

    /**
     * @var resource|null
     */
    private static $server;

    /**
     * @var int
     */
    private static $port = 0;

    /**
     * @var string
     */
    private static $outDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$outDir = sys_get_temp_dir() . '/yii2-sentry-tests-' . bin2hex(random_bytes(4));
        mkdir(self::$outDir . '/sessions', 0777, true);
        mkdir(self::$outDir . '/runtime', 0777, true);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string)stream_socket_get_name($socket, false);
        self::$port = (int)substr($name, (int)strrpos($name, ':') + 1);
        fclose($socket);

        $env = getenv();
        unset($env['PHP_CLI_SERVER_WORKERS']); // one worker: a request is finished before the next one starts
        $env['SENTRY_TEST_OUT_DIR'] = self::$outDir;

        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . self::$port . ' '
            . escapeshellarg(dirname(__DIR__) . '/Fixtures/WebApp/router.php');
        $server = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', self::$outDir . '/server.log', 'a'],
            2 => ['file', self::$outDir . '/server.log', 'a'],
        ], $pipes, null, $env);
        self::$server = is_resource($server) ? $server : null;

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errorCode, $errorMessage, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50000);
        }

        self::fail('The PHP built-in server did not start: ' . @file_get_contents(self::$outDir . '/server.log'));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    /**
     * @param list<string> $headers
     *
     * @return array{status: int, body: string, sessionCookie: string|null, events: list<array<string, mixed>>,
     *               transactions: list<array<string, mixed>>, appLog: string}
     */
    protected function request(string $scenario, string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $run = bin2hex(random_bytes(6));
        $headers[] = 'X-Test-Scenario: ' . $scenario;
        $headers[] = 'X-Test-Run: ' . $run;

        [$status, $responseHeaders, $responseBody] = self::http($method, $path, $headers, $body);
        // The server handles one request at a time: once this returns, the request above (including its
        // shutdown functions, where Yii flushes logs) has completed.
        self::http('GET', '/__barrier');

        $payloads = RecordingTransport::readFile(self::$outDir . "/$run.jsonl");
        $sessionCookie = preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;\s]+)/mi', $responseHeaders, $match) ? $match[1] : null;

        return [
            'status' => $status,
            'body' => $responseBody,
            'sessionCookie' => $sessionCookie,
            'events' => RecordingTransport::onlyType($payloads, 'event'),
            'transactions' => RecordingTransport::onlyType($payloads, 'transaction'),
            'appLog' => (string)@file_get_contents(self::$outDir . "/$run.log"),
        ];
    }

    /**
     * @param array{events: list<array<string, mixed>>, appLog: string} $response
     *
     * @return array<string, mixed>
     */
    protected function singleEvent(array $response): array
    {
        $this->assertCount(1, $response['events'], "Expected exactly one event to be sent to Sentry.\nApplication log:\n" . $response['appLog']);

        return $response['events'][0];
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function http(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $curl = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = (string)curl_exec($curl);
        $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);

        return [
            (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            substr($response, 0, $headerSize),
            (string)substr($response, $headerSize),
        ];
    }
}
