<?php

declare(strict_types=1);

// Unit tests build Yii applications inside PHPUnit, so PHPUnit keeps its own error handling.
// The web tests run the application in a separate server process with Yii's error handler enabled.
defined('YII_ENABLE_ERROR_HANDLER') or define('YII_ENABLE_ERROR_HANDLER', false);
defined('YII_DEBUG') or define('YII_DEBUG', false);
defined('YII_ENV') or define('YII_ENV', 'test');

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
