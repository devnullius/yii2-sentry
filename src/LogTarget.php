<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;
use UnexpectedValueException;
use Yii;
use yii\di\Instance;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target;

class LogTarget extends Target
{
    /**
     * Replaces what is left out of Yii's own warnings: the default text of [[Integration::$piiReplaceText]].
     */
    private const FILTERED = '[Filtered PII]';

    /**
     * The Sentry component to pass logs to, or its application component ID.
     *
     * @var Component|string
     */
    public $component = 'sentry';

    public function init(): void
    {
        parent::init();

        $this->component = Instance::ensure($this->component, Component::class);
        // The package logs its own failures (e.g. while adding request data to an event); those must not be sent
        // through the component that is failing.
        $this->except[] = 'devnullius\yii2\sentry\*';
    }

    /**
     * Yii's default prefix holds the client IP, user ID and session ID. A session ID is a credential, so the default
     * prefix is only kept when the SDK may send personal data (`send_default_pii`). A custom [[prefix]] is used as is.
     *
     * {@inheritdoc}
     */
    public function getMessagePrefix($message)
    {
        if ($this->prefix !== null || $this->shouldSendDefaultPii()) {
            return parent::getMessagePrefix($message);
        }

        return '';
    }

    /**
     * @inheritDoc
     */
    public function export()
    {
        // Get primary message source
        $primaryMessage = array_reduce($this->messages, static function ($message, $next) {
            // First iteration `$record` is null
            if (null === $message) {
                return $next;
            }

            // We need the most latest message with the most lowest level
            if ($next[1] <= $message[1]) {
                return $next;
            }

            return $message;
        });

        // Collect other logs, that appeared in request
        $logs = [];
        foreach ($this->messages as $message) {
            if ($message === $primaryMessage) {
                continue;
            }

            $logs[] = $this->formatMessage($this->scrubbed($message));
        }

        // Configure scope and capture error
        $this->component->getHub()->withScope(function (Scope $scope) use ($primaryMessage, $logs) {
            [$body, $level, $category, $timestamp] = $this->scrubbed($primaryMessage);

            if (!empty($category)) {
                $scope->setTag('category', $category);
            }

            $scope->setExtra('logs', [] === $logs ? 'N/A' : $logs);

            if ($body instanceof Throwable) {
                $this->component->getHub()->captureException($body);
            } else {
                $scope->setExtra('real_body', $body);
                $scope->setExtra('real_time', $this->getTime($timestamp));

                if (isset($primaryMessage[4])) {
                    $traces = [];

                    foreach ($primaryMessage[4] as $trace) {
                        $traces[] = "in {$trace['file']}:{$trace['line']}";
                    }

                    $scope->setExtra('traces', $traces);
                } else {
                    $scope->setExtra('traces', 'N/A');
                }

                if (is_string($body)) {
                    $text = $body;
                } else {
                    $text = VarDumper::export($body);
                }

                $this->component->getHub()->captureMessage($text, $this->getSeverityLevel($level));
            }
        });
    }

    protected function getTime($timestamp): string
    {
        $parent = parent::getTime($timestamp);

        return $parent . ' ' . Yii::$app->timeZone;
    }

    protected function getSeverityLevel($yiiLogLevel): ?Severity
    {
        switch ($yiiLogLevel) {
            case Logger::LEVEL_PROFILE_END:
            case Logger::LEVEL_PROFILE_BEGIN:
            case Logger::LEVEL_PROFILE:
            case Logger::LEVEL_TRACE:
                return Severity::debug();
            case Logger::LEVEL_INFO:
                return Severity::info();
            case Logger::LEVEL_WARNING:
                return Severity::warning();
            case Logger::LEVEL_ERROR:
                return Severity::error();
            default:
                throw new UnexpectedValueException("An unsupported Yii's log level \"{$yiiLogLevel}\" given.");
        }
    }

    /**
     * @inheritDoc
     */
    protected function getContextMessage()
    {
        // Mute context
        return '';
    }

    private function shouldSendDefaultPii(): bool
    {
        $client = $this->component->getHub()->getClient();

        return $client !== null && $client->getOptions()->shouldSendDefaultPii();
    }

    /**
     * The message without what the lines Yii and yii2-queue log themselves may hold and must not be sent.
     *
     * @param array<int, mixed> $message
     *
     * @return array<int, mixed>
     */
    private function scrubbed(array $message): array
    {
        return self::withoutJobData($this->withoutYiisSecrets($message));
    }

    /**
     * Yii logs two warnings with secrets and personal data in them: yii\web\User's about a login cookie or session
     * whose auth key doesn't match, with the user ID, the visitor's IP address and the key; and yii\db\Connection's
     * about a connection of a pool that couldn't be opened, with its DSN, which may hold the password. The key and the
     * password are left out, and the user ID and IP address too, unless the SDK may send personal data
     * (`send_default_pii`).
     *
     * @param array<int, mixed> $message
     *
     * @return array<int, mixed>
     */
    private function withoutYiisSecrets(array $message): array
    {
        $category = (string)($message[2] ?? '');
        $text = $message[0] ?? null;
        if (!is_string($text)) {
            return $message;
        }

        if (strpos($category, 'yii\web\User::') === 0) {
            // E.g. "Invalid cookie auth key attempted for user '42' from 203.0.113.10: <the key>"
            $keepPersonalData = $this->shouldSendDefaultPii();
            $text = preg_replace_callback(
                "/^(Invalid (?:cookie|session) auth key attempted for user ')(.*?)(' from )(\\S*)(: )(.*)$/s",
                static function (array $match) use ($keepPersonalData): string {
                    return $match[1] . ($keepPersonalData ? $match[2] : self::FILTERED) . $match[3]
                        . ($keepPersonalData ? $match[4] : self::FILTERED) . $match[5] . self::FILTERED;
                },
                $text
            );
        } elseif (strpos($category, 'yii\db\Connection::') === 0) {
            // E.g. "Connection (pgsql:host=replica;password=secret) failed: ...", or the line logged at the info level
            // when a connection is opened: "Opening DB connection: pgsql:host=db;password=secret".
            $text = preg_replace_callback(
                '/((?:^|[;:])\s*(?:password|pwd)\s*=).*?(?=;|\) failed: |$)/i',
                static function (array $match): string {
                    return $match[1] . self::FILTERED;
                },
                $text
            );
        } else {
            return $message;
        }

        // On a regular expression failure, send nothing rather than the data.
        $message[0] = $text ?? self::FILTERED;

        return $message;
    }

    /**
     * yii2-queue's LogBehavior logs the exception a failed job ended with as text, which may hold any data the job
     * handled: when a worker runs the job in a process of its own (yii2-queue's default) and that process fails, e.g.
     * because the job exited, everything the process printed; when a stored job can't be read as a job, e.g. because
     * its class was renamed after it was queued, a dump of it. That data is left out.
     *
     * @param array<int, mixed> $message
     *
     * @return array<int, mixed>
     */
    private static function withoutJobData(array $message): array
    {
        if (($message[2] ?? null) !== 'yii\queue\Queue' || !is_string($message[0] ?? null)) {
            return $message;
        }
        $text = $message[0];

        // Symfony's ProcessFailedException ends with the output; yii2-queue's line goes on with the stack trace.
        $output = strpos($text, "\n\nOutput:\n================\n");
        if ($output !== false) {
            $text = substr($text, 0, $output) . "\n\nOutput: [not sent]";
        }

        // yii2-queue's InvalidJobException, then the dump; the line goes on with the stack trace.
        $notAJob = 'Job must be a JobInterface instance instead of ';
        $dump = strpos($text, $notAJob);
        if ($dump !== false) {
            $start = $dump + strlen($notAJob);
            $text = substr($text, 0, $start) . self::typeOfDump(substr($text, $start)) . '.';
        }

        $message[0] = $text;

        return $message;
    }

    /**
     * @param string $dump how VarDumper shows a value, e.g. "app\jobs\SendJob#1\n(\n    [email] => ..."
     *
     * @return string the value's class, without its data
     */
    private static function typeOfDump(string $dump): string
    {
        if (preg_match('/^([\w\\\\]+)#\d+/', $dump, $object) !== 1) {
            return 'a value that is not an object';
        }

        // PHP's stand-in for an object of a class that can't be loaded: the class it was stored as.
        if (preg_match("/\\[__PHP_Incomplete_Class_Name\\] => '((?:[^'\\\\]|\\\\.)*)'/", $dump, $stored) === 1) {
            return $object[1] . ' (' . stripslashes($stored[1]) . ')';
        }

        return $object[1];
    }
}
