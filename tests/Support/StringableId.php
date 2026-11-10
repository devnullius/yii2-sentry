<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Support;

final class StringableId
{
    /**
     * @var string
     */
    private $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
