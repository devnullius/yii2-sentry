<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use yii\web\Session;

/**
 * A session that reports itself as open with a known ID, without touching PHP's real session handling.
 */
final class ActiveSession extends Session
{
    public const ID = 'SESSION0123456789abcdef';

    public function init()
    {
        // Skip yii\web\Session::init(): it would log "Session is already started" and register a shutdown hook.
    }

    public function getIsActive()
    {
        return true;
    }

    public function getId()
    {
        return self::ID;
    }

    public function close()
    {
    }
}
