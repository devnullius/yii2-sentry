<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Fixtures\WebApp\controllers;

use devnullius\yii2\sentry\tests\Support\Identity;
use RuntimeException;
use Yii;
use yii\web\Controller;

final class SiteController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionError(): string
    {
        $exception = Yii::$app->getErrorHandler()->exception;

        return 'error page' . ($exception !== null ? ': ' . $exception->getMessage() : '');
    }

    public function actionIndex(): string
    {
        return 'ok';
    }

    public function actionLogError(): string
    {
        Yii::error('something failed', 'app');

        return 'logged';
    }

    public function actionThrow(): void
    {
        throw new RuntimeException('boom from site/throw');
    }

    public function actionPostLog(): string
    {
        Yii::warning('suspicious thing during POST', 'app');

        return 'posted';
    }

    public function actionLogin(): string
    {
        Yii::$app->getUser()->login(new Identity());

        return 'logged in';
    }

    public function actionWarnThenThrow(): void
    {
        $id = Yii::$app->getUser()->getId(); // restores the identity from the session, so the session is open
        Yii::warning('minor issue before the crash', 'app');

        throw new RuntimeException('crash for user ' . var_export($id, true));
    }

    public function actionOuter(): string
    {
        return (string)$this->run('site/inner');
    }

    public function actionInner(): string
    {
        Yii::error('failure inside a nested action', 'app');

        return 'inner';
    }
}
