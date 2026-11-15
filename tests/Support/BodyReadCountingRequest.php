<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

use yii\web\Request;

/**
 * A web request that counts how often its raw body is read.
 */
final class BodyReadCountingRequest extends Request
{
    /**
     * @var int
     */
    public $rawBodyReads = 0;

    public function getRawBody()
    {
        $this->rawBodyReads++;

        return parent::getRawBody();
    }
}
