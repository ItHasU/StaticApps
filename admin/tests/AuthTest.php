<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private string $stateDir;
    private string $originalHash;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/staticapps-auth-' . bin2hex(random_bytes(8));
        putenv('ADMIN_STATE_DIR=' . $this->stateDir);
        $_SESSION = [];
        $this->originalHash = password_hash('correct-password', PASSWORD_DEFAULT);
        putenv('ADMIN_PASSWORD_HASH=' . $this->originalHash);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stateDir)) {
            remove_directory_recursive($this->stateDir);
        }
        putenv('ADMIN_STATE_DIR');
        putenv('ADMIN_PASSWORD_HASH');
    }

    public function testCorrectPasswordAndCsrfSucceeds(): void
    {
        $token = csrf_token();

        $result = attempt_login('correct-password', $token, '1.1.1.1');

        $this->assertTrue($result['success']);
    }

    public function testWrongPasswordFails(): void
    {
        $token = csrf_token();

        $result = attempt_login('wrong-password', $token, '1.1.1.2');

        $this->assertFalse($result['success']);
        $this->assertSame('Mot de passe incorrect.', $result['message']);
    }

    public function testMissingCsrfTokenFails(): void
    {
        csrf_token();

        $result = attempt_login('correct-password', null, '1.1.1.3');

        $this->assertFalse($result['success']);
    }

    public function testInvalidCsrfTokenFails(): void
    {
        csrf_token();

        $result = attempt_login('correct-password', 'not-the-token', '1.1.1.4');

        $this->assertFalse($result['success']);
    }

    public function testLockoutAfterMaxFailedAttemptsBlocksEvenCorrectPassword(): void
    {
        $identifier = '1.1.1.5';
        $token = csrf_token();

        for ($i = 0; $i < LOGIN_MAX_ATTEMPTS; $i++) {
            attempt_login('wrong-password', $token, $identifier);
        }

        $result = attempt_login('correct-password', $token, $identifier);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Trop de tentatives', $result['message']);
    }
}
