<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use yii\base\BaseObject;
use yii\web\IdentityInterface;

/**
 * A user whose ID is an object, like yii2-mongodb's MongoDB\BSON\ObjectId (stringable, but neither int nor string).
 */
final class ObjectIdIdentity extends BaseObject implements IdentityInterface
{
    public const ID = '507f1f77bcf86cd799439011';

    public static function findIdentity($id)
    {
        return null;
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null;
    }

    public function getId()
    {
        return new StringableId(self::ID);
    }

    public function getAuthKey()
    {
        return 'auth-key';
    }

    public function validateAuthKey($authKey)
    {
        return true;
    }
}
