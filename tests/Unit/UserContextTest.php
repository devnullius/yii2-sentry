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
    public function testNoUserIsSentWithoutPersonalDataByDefault(): void
    {
        $app = $this->createWebApp();

        $app->getUser()->login(new Identity());
        $app->get('sentry')->getHub()->captureMessage('after login');

        $this->assertNull($this->singleEvent()['user']['id'] ?? null);
    }

    public function testWithSendDefaultPiiTheUserIsSentOnEveryRequest(): void
    {
        $app = $this->createWebApp(['sentrySettings' => ['send_default_pii' => true]]);

        // A user restored from the session: Yii does not trigger its login event for that.
        $app->getUser()->setIdentity(new Identity());
        $app->get('sentry')->getHub()->captureMessage('a later request');

        $this->assertSame(42, $this->singleEvent()['user']['id'] ?? null);
    }

    public function testWithSendDefaultPiiLoginSetsTheUser(): void
    {
        $app = $this->createWebApp(['sentrySettings' => ['send_default_pii' => true]]);

        $app->getUser()->login(new Identity());
        $app->get('sentry')->getHub()->captureMessage('after login');

        $this->assertSame(42, $this->singleEvent()['user']['id'] ?? null);
    }

    public function testTheUserIdCanBeSentWithoutOtherPersonalData(): void
    {
        $app = $this->createWebApp(['sendUserId' => true]);

        $app->getUser()->setIdentity(new Identity());
        $app->get('sentry')->getHub()->captureMessage('a later request');

        $user = $this->singleEvent()['user'] ?? [];
        $this->assertSame(42, $user['id'] ?? null);
        $this->assertNull($user['ip_address'] ?? null, 'Only the ID: send_default_pii stays off.');
    }

    public function testTheUserIdCanBeTurnedOffEvenWithSendDefaultPii(): void
    {
        $app = $this->createWebApp(['sendUserId' => false, 'sentrySettings' => ['send_default_pii' => true]]);

        $app->getUser()->setIdentity(new Identity());
        $app->get('sentry')->getHub()->captureMessage('a later request');

        $user = $this->singleEvent()['user'] ?? [];
        $this->assertNull($user['id'] ?? null);
        $this->assertSame('203.0.113.10', $user['ip_address'] ?? null, 'The rest of the personal data is still sent.');
    }

    public function testLoginWithANonScalarIdentityIdKeepsWorking(): void
    {
        $app = $this->createWebApp(['sentrySettings' => ['send_default_pii' => true]]);

        $this->assertTrue($app->getUser()->login(new ObjectIdIdentity()), 'Sentry must never make a login fail.');
        $app->get('sentry')->getHub()->captureMessage('after login');

        $this->assertSame(ObjectIdIdentity::ID, $this->singleEvent()['user']['id'] ?? null);
    }
}
