<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\Console;

use yii\console\Controller;
use yii\console\ExitCode;

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
}
