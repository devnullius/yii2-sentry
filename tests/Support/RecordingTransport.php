<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use Sentry\Event;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * Records exactly what the SDK would send, without sending anything.
 *
 * Each event is serialized with the SDK's own PayloadSerializer. Payloads are kept in memory, or appended as one
 * JSON line per event to a file when the application runs in another process (the web tests).
 */
final class RecordingTransport implements TransportInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public $payloads = [];

    /**
     * @var string|null
     */
    private $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file;
    }

    public function send(Event $event): Result
    {
        $payload = self::serialize($event);

        if ($this->file !== null) {
            file_put_contents($this->file, json_encode($payload) . "\n", FILE_APPEND | LOCK_EX);
        } else {
            $this->payloads[] = $payload;
        }

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }

    /**
     * @return list<array<string, mixed>> error/message events, without transactions
     */
    public function events(): array
    {
        return self::onlyType($this->payloads, 'event');
    }

    /**
     * @return array<string, mixed> the event payload, plus its envelope item type under "_type"
     */
    public static function serialize(Event $event): array
    {
        $envelope = (new PayloadSerializer(new Options(['dsn' => 'https://public@sentry.example.com/1'])))->serialize($event);
        $lines = explode("\n", $envelope);
        $itemHeader = json_decode($lines[1] ?? 'null', true);
        $payload = json_decode($lines[2] ?? 'null', true);

        $payload = is_array($payload) ? $payload : [];
        $payload['_type'] = is_array($itemHeader) ? ($itemHeader['type'] ?? null) : null;

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function readFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $payloads = [];
        foreach (explode("\n", (string)file_get_contents($file)) as $line) {
            if ($line !== '') {
                $payloads[] = json_decode($line, true);
            }
        }

        return $payloads;
    }

    /**
     * @param list<array<string, mixed>> $payloads
     *
     * @return list<array<string, mixed>>
     */
    public static function onlyType(array $payloads, string $type): array
    {
        return array_values(array_filter($payloads, static function (array $payload) use ($type): bool {
            return ($payload['_type'] ?? null) === $type;
        }));
    }
}
