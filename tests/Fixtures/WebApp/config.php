<?php

declare(strict_types=1);

use devnullius\yii2\sentry\Component;
use devnullius\yii2\sentry\Integration;
use devnullius\yii2\sentry\LogTarget;
use devnullius\yii2\sentry\tests\Support\Identity;
use devnullius\yii2\sentry\tests\Support\RecordingTransport;
use yii\log\FileTarget;

/**
 * Application config for each web test scenario.
 *
 * Every scenario starts from the setup the README recommends (component + LogTarget for errors and warnings, 40x
 * excluded), the package's default integration list, an error action, and production settings (YII_DEBUG off).
 *
 * @return array<string, mixed>
 */
return static function (string $scenario, string $outDir, string $run): array {
    $sentry = [
        'class' => Component::class,
        'dsn' => 'https://public@sentry.example.com/1',
        'sentrySettings' => ['transport' => new RecordingTransport("$outDir/$run.jsonl")],
    ];
    $request = [
        'cookieValidationKey' => 'test',
        'enableCsrfValidation' => false,
        'scriptUrl' => '/index.php',
        'baseUrl' => '',
    ];
    $errorAction = 'site/error';
    $events = [];

    $withIntegration = static function (Integration $integration): array {
        $defaults = (new ReflectionClass(Component::class))->getDefaultProperties()['integrations'];

        return array_map(static function ($item) use ($integration) {
            return $item === Integration::class ? $integration : $item;
        }, $defaults);
    };

    switch ($scenario) {
        case 'default':
        case 'e_all':
            break;
        case 'pii_on':
            $sentry['sentrySettings']['send_default_pii'] = true;
            break;
        case 'pii_on_tracing':
            $sentry['sentrySettings']['send_default_pii'] = true;
            $sentry['sentrySettings']['traces_sample_rate'] = 1.0;
            break;
        case 'pii_nested':
            $integration = new Integration();
            $integration->piiBodyFields = ['site/post-log' => ['profile' => ['phone']]];
            $sentry['integrations'] = $withIntegration($integration);
            break;
        case 'existing_hub':
            // The client comes from \Sentry\init() in router.php.
            $sentry = ['class' => Component::class, 'useExistingHub' => true];
            break;
        case 'trusted_proxy':
            // Typical production: the app sits behind a load balancer that Yii trusts.
            $request['trustedHosts'] = ['127.0.0.1'];
            break;
        case 'api':
            // API-style app: no error action, and a failure before any action is resolved.
            $errorAction = null;
            $events['on beforeRequest'] = static function (): void {
                if (Yii::$app->getRequest()->getIsPost()) {
                    throw new RuntimeException('database unavailable');
                }
            };
            break;
        default:
            throw new InvalidArgumentException("Unknown test scenario '$scenario'.");
    }

    return array_merge([
        'id' => 'web-test',
        'basePath' => __DIR__,
        'vendorPath' => dirname(__DIR__, 3) . '/vendor',
        'runtimePath' => "$outDir/runtime",
        'controllerNamespace' => 'devnullius\yii2\sentry\tests\Fixtures\WebApp\controllers',
        'bootstrap' => ['sentry', 'log'],
        'components' => [
            'request' => $request,
            'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false],
            'session' => ['savePath' => "$outDir/sessions"],
            'user' => ['identityClass' => Identity::class, 'enableAutoLogin' => false],
            'errorHandler' => ['errorAction' => $errorAction],
            'sentry' => $sentry,
            'log' => [
                'targets' => [
                    'sentry' => [
                        'class' => LogTarget::class,
                        'levels' => ['error', 'warning'],
                        'except' => ['yii\web\HttpException:40*'],
                    ],
                    // Shows what happens to the log pipeline itself, e.g. Yii disabling a failing target.
                    'file' => [
                        'class' => FileTarget::class,
                        'logFile' => "$outDir/$run.log",
                        'levels' => ['error', 'warning'],
                        'logVars' => [],
                    ],
                ],
            ],
        ],
    ], $events);
};
