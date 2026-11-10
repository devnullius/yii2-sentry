<?php

declare(strict_types=1);

namespace devnullius\yii2\sentry\tests\Unit;

use devnullius\yii2\sentry\LogTarget;
use devnullius\yii2\sentry\tests\Support\ActiveSession;
use devnullius\yii2\sentry\tests\Support\Identity;
use devnullius\yii2\sentry\tests\Support\TestCase;
use Yii;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LogTargetTest extends TestCase
{
    public function testLogLinesDoNotContainTheClientIpUserIdOrSessionIdByDefault(): void
    {
        $this->appWithLogTarget();

        $logs = $this->exportTwoMessages();

        $this->assertStringContainsString('first problem', $logs);
        $this->assertStringNotContainsString(ActiveSession::ID, $logs, 'A session ID in Sentry allows session hijacking.');
        $this->assertStringNotContainsString('203.0.113.10', $logs);
        $this->assertStringNotContainsString('[42]', $logs);
    }

    public function testLogLinesKeepYiisPrefixWhenSendingPiiIsEnabled(): void
    {
        $this->appWithLogTarget(['send_default_pii' => true]);

        $logs = $this->exportTwoMessages();

        $this->assertStringContainsString('[203.0.113.10][42][' . ActiveSession::ID . ']', $logs);
    }

    public function testACustomPrefixIsRespected(): void
    {
        $this->appWithLogTarget([], ['prefix' => static function (): string {
            return '[custom]';
        }]);

        $logs = $this->exportTwoMessages();

        $this->assertStringContainsString('[custom]', $logs);
    }

    /**
     * @param array<string, mixed> $sentrySettings
     * @param array<string, mixed> $target
     */
    private function appWithLogTarget(array $sentrySettings = [], array $target = []): void
    {
        $app = $this->createWebApp(['sentrySettings' => $sentrySettings], [], [
            'session' => ['class' => ActiveSession::class],
            'log' => ['targets' => ['sentry' => array_merge([
                'class' => LogTarget::class,
                'levels' => ['error', 'warning'],
            ], $target)]],
        ]);
        $app->getLog();
        $app->getUser()->setIdentity(new Identity());
        $app->getSession();
    }

    private function exportTwoMessages(): string
    {
        Yii::warning('first problem', 'app');
        Yii::error('second problem', 'app');
        Yii::getLogger()->flush(true);

        return implode("\n", (array)($this->singleEvent()['extra']['logs'] ?? []));
    }
}
