<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\tests\Support\Identity;
use devnullius\yii2\sentry\tests\Support\ObjectIdIdentity;
use devnullius\yii2\sentry\tests\Support\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class UserContextTest extends TestCase
{
    public function testLoginSetsTheSentryUser(): void
    {
        $app = $this->createWebApp();

        $app->getUser()->login(new Identity());
        $app->get('sentry')->getHub()->captureMessage('after login');

        $this->assertSame(42, $this->singleEvent()['user']['id'] ?? null);
    }

    public function testLoginWithANonScalarIdentityIdKeepsWorking(): void
    {
        $app = $this->createWebApp();

        $this->assertTrue($app->getUser()->login(new ObjectIdIdentity()), 'Sentry must never make a login fail.');
        $app->get('sentry')->getHub()->captureMessage('after login');

        $this->assertSame(ObjectIdIdentity::ID, $this->singleEvent()['user']['id'] ?? null);
    }
}
