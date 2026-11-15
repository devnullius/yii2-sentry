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

    public function actionLogTwo(): string
    {
        Yii::error('first distinct problem', 'app');
        Yii::error('second distinct problem', 'app');

        return 'logged two';
    }

    public function actionWarning(): string
    {
        $values = [];

        return 'value: ' . $values['missing']; // PHP 8: E_WARNING "Undefined array key"
    }

    public function actionFatal(): void
    {
        ini_set('memory_limit', '64M');
        $chunks = [];
        while (true) {
            $chunks[] = str_repeat('x', 1024 * 1024);
        }
    }

    public function actionCompileError(): void
    {
        // A compile error: PHP stops at once, running only the shutdown functions.
        eval('function yii2_sentry_web_report() {} function yii2_sentry_web_report() {}');
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

    public function actionRender(): string
    {
        return $this->renderPartial('hello', ['name' => 'Ann']);
    }

    public function actionQuery(): string
    {
        return (string)Yii::$app->getDb()->createCommand('SELECT :email AS email', [':email' => 'ann@example.com'])->queryScalar();
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
