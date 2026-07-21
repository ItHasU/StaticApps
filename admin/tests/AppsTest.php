<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class AppsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/staticapps-apps-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/apps', 0777, true);
        mkdir($this->root . '/portal', 0777, true);
        putenv('APPS_DIR=' . $this->root . '/apps');
        putenv('PORTAL_DIR=' . $this->root . '/portal');
        putenv('PORTAL_HISTORY_DIR=' . $this->root . '/portal/history');
        putenv('PORTAL_ASSETS_SEED_DIR=' . $this->root . '/seed-portal');
    }

    protected function tearDown(): void
    {
        remove_directory_recursive($this->root);
        putenv('APPS_DIR');
        putenv('PORTAL_DIR');
        putenv('PORTAL_HISTORY_DIR');
        putenv('PORTAL_ASSETS_SEED_DIR');
    }

    public function testAppWithoutMetaUsesFolderNameAsTitle(): void
    {
        mkdir($this->root . '/apps/myapp');
        file_put_contents($this->root . '/apps/myapp/index.html', '<html></html>');

        $apps = scan_apps();

        $this->assertCount(1, $apps);
        $this->assertSame('myapp', $apps[0]['title']);
        $this->assertSame('', $apps[0]['description']);
        $this->assertSame('', $apps[0]['icon']);
    }

    public function testAppWithMetaUsesProvidedValues(): void
    {
        mkdir($this->root . '/apps/myapp');
        file_put_contents($this->root . '/apps/myapp/index.html', '<html></html>');
        file_put_contents($this->root . '/apps/myapp/meta.json', json_encode([
            'title' => 'My App',
            'description' => 'A description',
            'icon' => '🚀',
        ]));

        $apps = scan_apps();

        $this->assertSame('My App', $apps[0]['title']);
        $this->assertSame('A description', $apps[0]['description']);
        $this->assertSame('🚀', $apps[0]['icon']);
    }

    public function testFolderWithoutIndexHtmlIsIgnored(): void
    {
        mkdir($this->root . '/apps/broken');
        file_put_contents($this->root . '/apps/broken/readme.txt', 'nothing here');

        $this->assertCount(0, scan_apps());
    }

    public function testAppsAreSortedByTitleCaseInsensitive(): void
    {
        foreach (['zebra', 'apple', 'mango'] as $slug) {
            mkdir($this->root . "/apps/{$slug}");
            file_put_contents($this->root . "/apps/{$slug}/index.html", '<html></html>');
        }

        $titles = array_column(scan_apps(), 'title');

        $this->assertSame(['apple', 'mango', 'zebra'], $titles);
    }

    public function testDirectAppAccessUrlUsesSlug(): void
    {
        mkdir($this->root . '/apps/my-cool-app');
        file_put_contents($this->root . '/apps/my-cool-app/index.html', '<html></html>');

        regenerate_portal_menu();
        $html = file_get_contents($this->root . '/portal/index.html');

        $this->assertStringContainsString('href="/apps/my-cool-app/"', $html);
    }

    public function testXssInMetaIsEscapedInGeneratedHtml(): void
    {
        mkdir($this->root . '/apps/xss');
        file_put_contents($this->root . '/apps/xss/index.html', '<html></html>');
        file_put_contents($this->root . '/apps/xss/meta.json', json_encode([
            'title' => '<script>alert(1)</script>',
            'description' => '<img src=x onerror=alert(1)>',
        ]));

        regenerate_portal_menu();
        $html = file_get_contents($this->root . '/portal/index.html');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    public function testRegenerateArchivesPreviousVersionWithTimestampBeforeOverwriting(): void
    {
        mkdir($this->root . '/apps/app1');
        file_put_contents($this->root . '/apps/app1/index.html', '<html></html>');

        regenerate_portal_menu();
        $this->assertCount(0, glob($this->root . '/portal/history/*.html'));

        mkdir($this->root . '/apps/app2');
        file_put_contents($this->root . '/apps/app2/index.html', '<html></html>');
        regenerate_portal_menu();

        $archived = glob($this->root . '/portal/history/*.html');
        $this->assertCount(1, $archived);
        $this->assertMatchesRegularExpression('/index-\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}\.html$/', $archived[0]);
        $this->assertStringNotContainsString('app2', file_get_contents($archived[0]));
    }

    public function testManualRegenerationProducesSameResultAsAutomatic(): void
    {
        mkdir($this->root . '/apps/app1');
        file_put_contents($this->root . '/apps/app1/index.html', '<html></html>');

        regenerate_portal_menu();
        $first = file_get_contents($this->root . '/portal/index.html');

        regenerate_portal_menu();
        $second = file_get_contents($this->root . '/portal/index.html');

        $this->assertSame($first, $second);
    }

    public function testEmptyAppsDirectoryProducesEmptyState(): void
    {
        regenerate_portal_menu();
        $html = file_get_contents($this->root . '/portal/index.html');

        $this->assertStringContainsString('empty-state', $html);
    }

    public function testRegenerateSyncsPortalAssetsFromSeed(): void
    {
        mkdir($this->root . '/seed-portal', 0777, true);
        file_put_contents($this->root . '/seed-portal/style.css', 'body { color: red; }');

        regenerate_portal_menu();

        $this->assertSame(
            'body { color: red; }',
            file_get_contents($this->root . '/portal/style.css')
        );
    }

    public function testRegenerateOverwritesOutdatedPortalAsset(): void
    {
        mkdir($this->root . '/seed-portal', 0777, true);
        file_put_contents($this->root . '/seed-portal/style.css', 'body { color: blue; }');
        file_put_contents($this->root . '/portal/style.css', 'body { color: old; }');

        regenerate_portal_menu();

        $this->assertSame(
            'body { color: blue; }',
            file_get_contents($this->root . '/portal/style.css')
        );
    }

    public function testRegenerateNeverCopiesIndexHtmlFromSeed(): void
    {
        mkdir($this->root . '/seed-portal', 0777, true);
        file_put_contents($this->root . '/seed-portal/index.html', '<html>seed leftover</html>');

        regenerate_portal_menu();
        $html = file_get_contents($this->root . '/portal/index.html');

        $this->assertStringNotContainsString('seed leftover', $html);
    }
}
