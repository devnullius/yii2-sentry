<?php

declare(strict_types=1);

// Router for PHP's built-in web server, started by WebServerTestCase. The SAPI is "cli-server", so Sentry's own
// RequestIntegration is active, just like under PHP-FPM.

if (($_SERVER['REQUEST_URI'] ?? '') === '/__barrier') {
    http_response_code(204);

    return true;
}

$scenario = (string)($_SERVER['HTTP_X_TEST_SCENARIO'] ?? 'default');
$run = preg_replace('/[^a-f0-9]/', '', (string)($_SERVER['HTTP_X_TEST_RUN'] ?? ''));
$outDir = (string)getenv('SENTRY_TEST_OUT_DIR');

// Like php.ini-production, deprecations are not reported, except in the "e_all" scenario (PHP's built-in default,
// which is what you get without a php.ini, e.g. in the official Docker images).
error_reporting($scenario === 'e_all' ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

define('YII_DEBUG', false);
define('YII_ENV', 'prod');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (strpos($scenario, 'existing_hub') === 0) {
    // Like an app that sets up Sentry right after Composer's autoloader, before Yii exists (GitHub issue #1).
    $sentryOptions = [
        'dsn' => 'https://public@sentry.example.com/1',
        'transport' => new devnullius\yii2\sentry\tests\Support\RecordingTransport("$outDir/" . ($run !== '' ? $run : 'unnamed') . '.jsonl'),
    ];
    if ($scenario === 'existing_hub_pii') {
        $sentryOptions['send_default_pii'] = true;
    } elseif ($scenario === 'existing_hub_data_collection') {
        $sentryOptions['data_collection'] = ['http_bodies' => []];
    }
    \Sentry\init($sentryOptions);
}

require dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/config.php';

(new yii\web\Application($config($scenario, $outDir, $run !== '' ? $run : 'unnamed')))->run();
