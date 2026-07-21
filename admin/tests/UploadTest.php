<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZipArchive;

final class UploadTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/staticapps-upload-' . bin2hex(random_bytes(8));
        mkdir($this->workDir . '/apps', 0777, true);
        mkdir($this->workDir . '/portal', 0777, true);
        putenv('APPS_DIR=' . $this->workDir . '/apps');
        putenv('PORTAL_DIR=' . $this->workDir . '/portal');
        putenv('PORTAL_HISTORY_DIR=' . $this->workDir . '/portal/history');
        putenv('ADMIN_STATE_DIR=' . $this->workDir . '/.admin-state');
        putenv('MAX_UPLOAD_SIZE_MB=50');
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        remove_directory_recursive($this->workDir);
        putenv('APPS_DIR');
        putenv('PORTAL_DIR');
        putenv('PORTAL_HISTORY_DIR');
        putenv('ADMIN_STATE_DIR');
        putenv('MAX_UPLOAD_SIZE_MB');
    }

    private function makeZip(string $name, array $entries): string
    {
        $path = $this->workDir . '/' . $name;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entryName => $content) {
            $zip->addFromString($entryName, $content);
        }
        $zip->close();

        return $path;
    }

    private function uploadPostAndFiles(string $zipPath, string $slug, bool $overwrite = false): array
    {
        return [
            [
                'csrf_token' => csrf_token(),
                'slug' => $slug,
                'overwrite' => $overwrite ? '1' : '',
            ],
            [
                'app_zip' => [
                    'error' => UPLOAD_ERR_OK,
                    'tmp_name' => $zipPath,
                    'size' => filesize($zipPath),
                ],
            ],
        ];
    }

    public function testEntryPathIsSafeRejectsTraversalAndAbsolutePaths(): void
    {
        $this->assertFalse(entry_path_is_safe('../evil'));
        $this->assertFalse(entry_path_is_safe('a/../../evil'));
        $this->assertFalse(entry_path_is_safe('/etc/passwd'));
        $this->assertFalse(entry_path_is_safe('C:\\evil'));
        $this->assertTrue(entry_path_is_safe('a/b/c.html'));
        $this->assertTrue(entry_path_is_safe('index.html'));
    }

    public function testValidZipIsAcceptedAndExtracted(): void
    {
        $zip = $this->makeZip('good.zip', ['index.html' => '<html></html>']);

        $result = validate_and_extract_zip($zip);

        $this->assertNull($result['error']);
        $this->assertFileExists($result['path'] . '/index.html');
    }

    public function testZipWithoutIndexHtmlIsRejected(): void
    {
        $zip = $this->makeZip('noindex.zip', ['readme.txt' => 'hello']);

        $result = validate_and_extract_zip($zip);

        $this->assertNotNull($result['error']);
        $this->assertNull($result['path']);
    }

    public function testZipSlipEntryIsRejectedBeforeExtraction(): void
    {
        $marker = dirname($this->workDir) . '/staticapps-zip-slip-marker';
        $zip = $this->makeZip('slip.zip', [
            'index.html' => '<html></html>',
            '../../../staticapps-zip-slip-marker' => 'evil',
        ]);

        $result = validate_and_extract_zip($zip);

        $this->assertNotNull($result['error']);
        $this->assertNull($result['path']);
        $this->assertFileDoesNotExist($marker);
    }

    public function testZipBombCompressionRatioIsRejected(): void
    {
        $zip = $this->makeZip('bomb.zip', ['index.html' => str_repeat('0', 5 * 1024 * 1024)]);

        $result = validate_and_extract_zip($zip);

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('compression', $result['error']);
    }

    public function testTooManyEntriesIsRejected(): void
    {
        $entries = ['index.html' => '<html></html>'];
        for ($i = 0; $i < 600; $i++) {
            $entries["f{$i}.txt"] = 'x';
        }
        $zip = $this->makeZip('many.zip', $entries);

        $result = validate_and_extract_zip($zip);

        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('trop de fichiers', $result['error']);
    }

    public function testFakeZipRenamedWithZipExtensionIsRejected(): void
    {
        $path = $this->workDir . '/fake.zip';
        file_put_contents($path, 'this is definitely not a zip file');

        $result = validate_and_extract_zip($path);

        $this->assertNotNull($result['error']);
    }

    public function testNestedSingleRootDirectoryIsFlattened(): void
    {
        $zip = $this->makeZip('nested.zip', ['wrapper/index.html' => '<html>nested</html>']);

        $result = validate_and_extract_zip($zip);

        $this->assertNull($result['error']);
        $this->assertFileExists($result['path'] . '/index.html');
    }

    public function testExtractedFilesHaveExecuteBitStripped(): void
    {
        $zip = $this->makeZip('good.zip', ['index.html' => '<html></html>']);

        $result = validate_and_extract_zip($zip);

        $perms = fileperms($result['path'] . '/index.html') & 0777;
        $this->assertSame(0644, $perms);
    }

    /**
     * Le dossier d'extraction lui-même doit rester traversable par un
     * processus tiers (le service web, dans un autre conteneur) une fois
     * l'app publiée — pas seulement son contenu.
     */
    public function testExtractedRootDirectoryIsReadableByOtherProcesses(): void
    {
        $zip = $this->makeZip('good.zip', ['index.html' => '<html></html>']);

        $result = validate_and_extract_zip($zip);

        $perms = fileperms($result['path']) & 0777;
        $this->assertSame(0755, $perms);
    }

    public function testFullUploadPublishesAppAndRegeneratesMenu(): void
    {
        $zip = $this->makeZip('good.zip', ['index.html' => '<html>hello</html>']);
        [$post, $files] = $this->uploadPostAndFiles($zip, 'goodapp');

        $result = attempt_upload($post, $files, '2.2.2.1');

        $this->assertTrue($result['success']);
        $this->assertFileExists($this->workDir . '/apps/goodapp/index.html');
        $this->assertFileExists($this->workDir . '/portal/index.html');
        $this->assertStringContainsString('goodapp', file_get_contents($this->workDir . '/portal/index.html'));
    }

    public function testInvalidSlugIsRejectedWithoutPublishing(): void
    {
        $zip = $this->makeZip('good.zip', ['index.html' => '<html></html>']);
        [$post, $files] = $this->uploadPostAndFiles($zip, 'Not A Valid Slug');

        $result = attempt_upload($post, $files, '2.2.2.2');

        $this->assertFalse($result['success']);
        $this->assertDirectoryDoesNotExist($this->workDir . '/apps/Not A Valid Slug');
    }

    public function testExistingFolderWithoutOverwriteIsRejected(): void
    {
        mkdir($this->workDir . '/apps/goodapp', 0777, true);
        file_put_contents($this->workDir . '/apps/goodapp/index.html', '<html>original</html>');

        $zip = $this->makeZip('good.zip', ['index.html' => '<html>new</html>']);
        [$post, $files] = $this->uploadPostAndFiles($zip, 'goodapp', overwrite: false);

        $result = attempt_upload($post, $files, '2.2.2.3');

        $this->assertFalse($result['success']);
        $this->assertSame('<html>original</html>', file_get_contents($this->workDir . '/apps/goodapp/index.html'));
    }

    public function testExistingFolderWithOverwriteReplacesContent(): void
    {
        mkdir($this->workDir . '/apps/goodapp', 0777, true);
        file_put_contents($this->workDir . '/apps/goodapp/index.html', '<html>original</html>');

        $zip = $this->makeZip('good.zip', ['index.html' => '<html>new</html>']);
        [$post, $files] = $this->uploadPostAndFiles($zip, 'goodapp', overwrite: true);

        $result = attempt_upload($post, $files, '2.2.2.4');

        $this->assertTrue($result['success']);
        $this->assertSame('<html>new</html>', file_get_contents($this->workDir . '/apps/goodapp/index.html'));
    }

    public function testInvalidCsrfTokenRejectsUploadWithoutTouchingFilesystem(): void
    {
        csrf_token();
        $zip = $this->makeZip('good.zip', ['index.html' => '<html></html>']);

        $result = attempt_upload(
            ['csrf_token' => 'wrong-token', 'slug' => 'goodapp'],
            ['app_zip' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => $zip, 'size' => filesize($zip)]],
            '2.2.2.5'
        );

        $this->assertFalse($result['success']);
        $this->assertDirectoryDoesNotExist($this->workDir . '/apps/goodapp');
    }
}
