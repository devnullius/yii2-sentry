<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use yii\base\BaseObject;
use yii\web\IdentityInterface;

/**
 * A user with an integer ID (42), restorable from the session.
 */
final class Identity extends BaseObject implements IdentityInterface
{
    public $id = 42;

    public static function findIdentity($id)
    {
        return (string)$id === '42' ? new self() : null;
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        return 'auth-key';
    }

    public function validateAuthKey($authKey)
    {
        return $authKey === 'auth-key';
    }
}
