<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Console;

use yii\console\Controller;
use yii\console\ExitCode;
use yii\console\Response;

final class HelloController extends Controller
{
    public function actionIndex(): int
    {
        return ExitCode::OK;
    }

    public function actionFail(): int
    {
        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Runs another command itself, as some commands do.
     */
    public function actionChain(): int
    {
        return (int)$this->run('index');
    }

    /**
     * Ends with a response of its own, as an action may.
     */
    public function actionRespond(): Response
    {
        $response = new Response();
        $response->exitStatus = ExitCode::UNAVAILABLE;

        return $response;
    }
}
