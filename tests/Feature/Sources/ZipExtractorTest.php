<?php

namespace Tests\Feature\Sources;

use App\Domain\Sources\Manifest;
use App\Domain\Sources\ManifestEntry;
use App\Domain\Sources\SourceRejection;
use App\Domain\Sources\ZipExtractor;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsZips;
use Tests\TestCase;
use ZipArchive;

class ZipExtractorTest extends TestCase
{
    use BuildsZips;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = storage_path('framework/testing/zip-extract-'.uniqid());
        @unlink($this->sentinelPath());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    private function rejects(string $code, string $archive): void
    {
        File::deleteDirectory($this->out); // each attempt gets a fresh folder, as in the importer
        try {
            (new ZipExtractor)->extract($archive, $this->out);
            $this->fail("expected rejection {$code}");
        } catch (SourceRejection $e) {
            $this->assertSame($code, $e->failureCode);
            $this->assertStringNotContainsString('evil', $e->getMessage(), 'no entry names in messages');
        }
    }

    public function test_a_nested_laravel_project_with_a_big_vendor_folder_extracts_safely()
    {
        $excluded = (new ZipExtractor)->extract($this->laravelZip(vendorFiles: 3000), $this->out);

        $this->assertFileExists("{$this->out}/myapp/artisan");
        $this->assertFileExists("{$this->out}/myapp/app/Sentinel.php");
        $this->assertDirectoryDoesNotExist("{$this->out}/myapp/vendor", 'vendor/ is never extracted');
        $this->assertFileDoesNotExist("{$this->out}/myapp/.env");
        $this->assertFileDoesNotExist("{$this->out}/myapp/storage/bundle.zip", 'nested archives are never written');
        $this->assertFileDoesNotExist($this->sentinelPath(), 'uploaded code was executed');
        $this->assertSame(0644, fileperms("{$this->out}/myapp/artisan") & 0777, 'no execute bits');

        $reasons = array_count_values(array_map(fn ($e) => $e->excludedReason, $excluded));
        $this->assertSame(['secret' => 1, 'nested_archive' => 1, 'dependency_or_vcs' => 1], $reasons, 'vendor/ is one entry, not 3000');
        $vendor = collect($excluded)->firstWhere('excludedReason', 'dependency_or_vcs');
        $this->assertSame('myapp/vendor/', $vendor->path);
        $this->assertSame(collect(range(0, 2999))->sum(fn ($i) => strlen("<?php // vendor file {$i}\n")), $vendor->bytes);
    }

    public function test_path_traversal_and_absolute_entries_are_rejected()
    {
        foreach (['../evil.php', 'a/../../evil.php', '/etc/cron.d/evil'] as $name) {
            $this->rejects('unsafe_path', $this->zip(['ok.php' => '<?php', $name => 'x']));
        }
        $this->assertFileDoesNotExist(dirname($this->out).'/evil.php');
    }

    public function test_symlinks_special_files_and_ignored_folders_are_skipped_not_recreated()
    {
        $excluded = (new ZipExtractor)->extract($this->zip([
            'artisan' => '<?php',
            'public/storage' => ['../storage/app/public', 0120777], // what `php artisan storage:link` leaves behind
            'evil' => ['/etc/passwd', 0120777],
            'node_modules/.bin/vite' => ['../vite/bin/vite.js', 0120777],
            'fifo' => ['', 0010644],
        ]), $this->out);

        $this->assertFileExists("{$this->out}/artisan");
        foreach (['public/storage', 'evil', 'fifo', 'node_modules'] as $path) {
            $this->assertFalse(file_exists("{$this->out}/{$path}") || is_link("{$this->out}/{$path}"), "{$path} must not be written");
        }

        $reasons = collect($excluded)->mapWithKeys(fn ($e) => [$e->path => $e->excludedReason])->all();
        $this->assertSame(['public/storage' => 'symlink', 'evil' => 'symlink', 'fifo' => 'special_file', 'node_modules/' => 'dependency_or_vcs'], $reasons);
    }

    public function test_configured_folders_do_not_count_toward_the_unpacked_source_limit()
    {
        config(['sources.max_total_bytes' => 100]);

        $excluded = (new ZipExtractor)->extract($this->zip([
            'myapp/artisan' => '<?php',
            'myapp/composer.json' => '{}',
            'myapp/app/User.php' => str_repeat('a', 60),
            'myapp/public/build/assets/app.js' => str_repeat('x', 500),
            'myapp/public/build/assets/app.css' => str_repeat('y', 500),
        ]), $this->out);

        $this->assertFileExists("{$this->out}/myapp/app/User.php");
        $this->assertDirectoryDoesNotExist("{$this->out}/myapp/public");
        $public = collect($excluded)->firstWhere('excludedReason', 'ignored_directory');
        $this->assertSame('myapp/public/', $public->path);
        $this->assertSame(1000, $public->bytes);
    }

    public function test_a_wrapper_named_public_is_kept_while_the_laravel_public_folder_is_skipped()
    {
        $excluded = (new ZipExtractor)->extract($this->zip([
            'mart_copy/public/artisan' => '<?php',
            'mart_copy/public/composer.json' => '{}',
            'mart_copy/public/app/User.php' => '<?php',
            'mart_copy/public/public/build/app.js' => str_repeat('x', 500),
        ]), $this->out);

        $this->assertFileExists("{$this->out}/mart_copy/public/app/User.php");
        $this->assertDirectoryDoesNotExist("{$this->out}/mart_copy/public/public");
        $ignored = collect($excluded)->firstWhere('excludedReason', 'ignored_directory');
        $this->assertSame('mart_copy/public/public/', $ignored->path);
        $this->assertSame(500, $ignored->bytes);
    }

    public function test_bombs_and_oversized_archives_are_rejected()
    {
        // 4 MB of zeros compresses to a few KB: far beyond a normal ratio.
        $this->rejects('compression_ratio', $this->zip(['zeros.txt' => str_repeat('0', 4 * 1048576)]));

        // Only what is actually unpacked counts toward the kept-source budget.
        config(['sources.max_total_bytes' => 100]);
        $this->rejects('too_large', $this->zip(['a.txt' => str_repeat('a', 60), 'b.txt' => str_repeat('b', 60)]));

        config(['sources.max_total_bytes' => 1 << 30, 'sources.zip.max_entries' => 2]);
        $this->rejects('too_many_files', $this->zip(['a' => '1', 'b' => '2', 'c' => '3']));

        config(['sources.zip.max_entries' => 1000, 'sources.zip.max_depth' => 3]);
        $this->rejects('too_deep', $this->zip(['a/b/c/d.php' => '<?php']));
    }

    public function test_unsupported_malformed_and_encrypted_archives_are_rejected()
    {
        $text = tempnam(sys_get_temp_dir(), 'not-zip');
        file_put_contents($text, 'just text');
        $this->rejects('unsupported_format', $text);

        $broken = tempnam(sys_get_temp_dir(), 'broken');
        file_put_contents($broken, "PK\x03\x04".random_bytes(200));
        $this->rejects('malformed_archive', $broken);

        $this->rejects('encrypted_archive', $this->zip(['a.php' => '<?php'], fn (ZipArchive $z) => $z->setEncryptionName('a.php', ZipArchive::EM_AES_256, 'pw')));
    }

    public function test_names_that_collide_after_normalization_are_rejected_before_writing()
    {
        $this->rejects('path_collision', $this->zip(['App/User.php' => 'a', 'app/user.php' => 'b']));
        $this->rejects('path_collision', $this->zip(["caf\u{00E9}.php" => 'nfc', "cafe\u{0301}.php" => 'nfd']));
    }

    public function test_file_folder_clashes_case_only_folders_and_long_names_are_rejected_on_every_os()
    {
        $this->rejects('path_collision', $this->zip(['a' => 'file', 'a/b.php' => 'inside']));
        $this->rejects('path_collision', $this->zip(['a/b.php' => 'inside', 'a' => 'file']));
        $this->rejects('path_collision', $this->zip(['Docs/x.md' => '1', 'docs/y.md' => '2']));
        $this->rejects('unsafe_path', $this->zip([str_repeat('n', 256).'.php' => '<?php']));
    }

    public function test_the_number_of_files_and_folders_created_is_capped()
    {
        config(['sources.max_files' => 3]);
        $this->rejects('too_many_files', $this->zip(['a.php' => '', 'b.php' => '', 'c.php' => '', 'd.php' => '']));
        $this->rejects('too_many_files', $this->zip(['a/x' => '', 'b/x' => '', 'c/x' => '', 'd/x' => '']));
    }

    public function test_a_hundred_thousand_excluded_entries_do_not_slow_the_manifest_down()
    {
        $manifest = new Manifest;
        $start = microtime(true);
        for ($i = 0; $i < 100000; $i++) {
            $manifest->add(new ManifestEntry("vendor/p{$i}/x.php", 10, null, 'php', 'dependency_or_vcs'));
            $manifest->storedBytes();
        }
        $this->assertLessThan(5, microtime(true) - $start);
    }
}
