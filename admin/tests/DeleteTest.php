<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class DeleteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/staticapps-delete-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/apps', 0777, true);
        mkdir($this->root . '/portal', 0777, true);
        putenv('APPS_DIR=' . $this->root . '/apps');
        putenv('PORTAL_DIR=' . $this->root . '/portal');
        putenv('PORTAL_HISTORY_DIR=' . $this->root . '/portal/history');
        putenv('ADMIN_STATE_DIR=' . $this->root . '/.admin-state');
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        remove_directory_recursive($this->root);
        putenv('APPS_DIR');
        putenv('PORTAL_DIR');
        putenv('PORTAL_HISTORY_DIR');
        putenv('ADMIN_STATE_DIR');
    }

    private function createApp(string $slug): void
    {
        mkdir($this->root . "/apps/{$slug}", 0777, true);
        file_put_contents($this->root . "/apps/{$slug}/index.html", '<html></html>');
    }

    public function testDeleteWithoutConfirmationDoesNothing(): void
    {
        $this->createApp('testapp');
        $token = csrf_token();

        $result = attempt_delete(['csrf_token' => $token, 'slug' => 'testapp']);

        $this->assertFalse($result['success']);
        $this->assertDirectoryExists($this->root . '/apps/testapp');
    }

    public function testDeleteWithConfirmationRemovesAppAndRegeneratesMenu(): void
    {
        $this->createApp('testapp');
        $token = csrf_token();

        $result = attempt_delete(['csrf_token' => $token, 'slug' => 'testapp', 'confirm' => '1']);

        $this->assertTrue($result['success']);
        $this->assertDirectoryDoesNotExist($this->root . '/apps/testapp');
        $this->assertFileExists($this->root . '/portal/index.html');
        $this->assertStringNotContainsString('testapp', file_get_contents($this->root . '/portal/index.html'));
    }

    public function testDeleteWithInvalidCsrfTokenIsRejected(): void
    {
        $this->createApp('testapp');
        csrf_token();

        $result = attempt_delete(['csrf_token' => 'wrong', 'slug' => 'testapp', 'confirm' => '1']);

        $this->assertFalse($result['success']);
        $this->assertDirectoryExists($this->root . '/apps/testapp');
    }

    public function testDeleteOfNonexistentAppIsRejected(): void
    {
        $token = csrf_token();

        $result = attempt_delete(['csrf_token' => $token, 'slug' => 'does-not-exist', 'confirm' => '1']);

        $this->assertFalse($result['success']);
    }

    public function testDeleteWithInvalidSlugIsRejected(): void
    {
        $token = csrf_token();

        $result = attempt_delete(['csrf_token' => $token, 'slug' => '../etc', 'confirm' => '1']);

        $this->assertFalse($result['success']);
    }
}
