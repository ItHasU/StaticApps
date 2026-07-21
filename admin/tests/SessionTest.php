<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    public function testIdleSessionIsClearedAfterTimeout(): void
    {
        session_start();
        $_SESSION['last_activity'] = time() - (SESSION_IDLE_TIMEOUT_SECONDS + 1);
        $_SESSION['authenticated'] = true;

        enforce_idle_timeout();

        $this->assertArrayNotHasKey('authenticated', $_SESSION);
    }

    public function testActiveSessionIsNotCleared(): void
    {
        session_start();
        $_SESSION['last_activity'] = time();
        $_SESSION['authenticated'] = true;

        enforce_idle_timeout();

        $this->assertTrue($_SESSION['authenticated']);
    }
}
