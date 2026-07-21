<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/staticapps-state-' . bin2hex(random_bytes(8));
        putenv('ADMIN_STATE_DIR=' . $this->stateDir);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stateDir)) {
            remove_directory_recursive($this->stateDir);
        }
        putenv('ADMIN_STATE_DIR');
    }

    #[DataProvider('validSlugProvider')]
    public function testValidSlugsAreAccepted(string $slug): void
    {
        $this->assertTrue(is_valid_app_slug($slug));
    }

    public static function validSlugProvider(): array
    {
        return [
            'single word' => ['app'],
            'with numbers' => ['mon-app-1'],
            'multiple hyphens' => ['a1-b2-c3'],
        ];
    }

    #[DataProvider('invalidSlugProvider')]
    public function testInvalidSlugsAreRejected(string $slug): void
    {
        $this->assertFalse(is_valid_app_slug($slug));
    }

    public static function invalidSlugProvider(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Mon App'],
            'spaces' => ['has space'],
            'path traversal' => ['../etc'],
            'leading hyphen' => ['-leading'],
            'trailing hyphen' => ['trailing-'],
            'underscore' => ['has_underscore'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    public function testCsrfTokenIsStableWithinSession(): void
    {
        $this->assertSame(csrf_token(), csrf_token());
    }

    public function testCsrfVerifyRejectsWrongOrMissingToken(): void
    {
        csrf_token();

        $this->assertFalse(csrf_verify('wrong-token'));
        $this->assertFalse(csrf_verify(null));
        $this->assertFalse(csrf_verify(''));
    }

    public function testCsrfVerifyAcceptsCorrectToken(): void
    {
        $token = csrf_token();

        $this->assertTrue(csrf_verify($token));
    }

    public function testRateLimitAllowsUpToConfiguredMaxThenBlocks(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(rate_limit_allow('test-bucket', '1.2.3.4', 3, 60));
        }

        $this->assertFalse(rate_limit_allow('test-bucket', '1.2.3.4', 3, 60));
    }

    public function testRateLimitIsIsolatedPerIdentifier(): void
    {
        for ($i = 0; $i < 3; $i++) {
            rate_limit_allow('bucket', 'ip-a', 3, 60);
        }

        $this->assertTrue(rate_limit_allow('bucket', 'ip-b', 3, 60));
    }

    public function testLoginLockoutIsActiveAfterMaxFailedAttempts(): void
    {
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) {
            register_failed_login('9.9.9.9');
        }

        $this->assertGreaterThan(0, login_lockout_remaining('9.9.9.9'));
    }

    public function testLoginLockoutIsNotActiveBeforeMaxAttempts(): void
    {
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS - 1; $i++) {
            register_failed_login('7.7.7.7');
        }

        $this->assertSame(0, login_lockout_remaining('7.7.7.7'));
    }

    public function testClearLoginAttemptsResetsLockout(): void
    {
        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) {
            register_failed_login('8.8.8.8');
        }

        clear_login_attempts('8.8.8.8');

        $this->assertSame(0, login_lockout_remaining('8.8.8.8'));
    }
}
