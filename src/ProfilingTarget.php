<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use yii\log\Logger;
use yii\log\Target;

/**
 * Hands the SQL profiling markers Yii logs to the tracer, whenever the log is flushed.
 *
 * @internal
 */
final class ProfilingTarget extends Target
{
    /**
     * The categories Yii profiles SQL queries under.
     */
    public const CATEGORIES = ['yii\db\Command::query', 'yii\db\Command::execute'];

    /**
     * @var int
     */
    public $exportInterval = 1;
    /**
     * @var array<int, string>
     */
    public $logVars = [];

    /**
     * @var callable(list<array<int, mixed>>): void
     */
    private $receiver;

    /**
     * @param callable(list<array<int, mixed>>): void $receiver
     * @param array<string, mixed> $config
     */
    public function __construct(callable $receiver, array $config = [])
    {
        $this->receiver = $receiver;
        parent::__construct($config);
    }

    public function init()
    {
        parent::init();

        $this->setLevels(Logger::LEVEL_PROFILE);
        $this->categories = self::CATEGORIES;
    }

    public function export()
    {
        ($this->receiver)($this->messages);
    }

    protected function getContextMessage()
    {
        return '';
    }
}
