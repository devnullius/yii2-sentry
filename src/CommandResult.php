<?php
declare(strict_types=1);

namespace devnullius\yii2\sentry;

use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Controller;
use yii\console\Application as ConsoleApplication;
use yii\console\Response;

/**
 * Whether the console command of an application failed, as far as its own process can know: by its exit code, unless
 * Yii ends the command before that is known.
 *
 * @internal
 */
final class CommandResult
{
    /**
     * @var ConsoleApplication
     */
    private $app;
    /**
     * @var Response|null the response the command's action returned: the command ends with its exit code
     */
    private $response;

    /**
     * Starts watching the command, while the application bootstraps.
     *
     * @param callable(string, string, callable): void $listen registers a class-level event handler
     */
    public function __construct(ConsoleApplication $app, callable $listen)
    {
        $this->app = $app;

        $listen(Controller::class, Controller::EVENT_AFTER_ACTION, function (ActionEvent $event) use ($app): void {
            if ($event->action === $app->requestedAction && $event->result instanceof Response) {
                $this->response = $event->result;
            }
        });
    }

    /**
     * Whether the command failed, as the application's request ends ([[Application::EVENT_AFTER_REQUEST]]): its exit
     * code isn't 0, or can't be known. Application::end() ends the request before the exit code it was given is
     * known, e.g. one the command passed to Yii::$app->end() or an ExitException.
     */
    public function failed(): bool
    {
        return self::isApplicationEnding() || (int)($this->response ?? $this->app->getResponse())->exitStatus !== 0;
    }

    private static function isApplicationEnding(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if ($frame['function'] === 'end' && isset($frame['class']) && is_a($frame['class'], Application::class, true)) {
                return true;
            }
        }

        return false;
    }
}
