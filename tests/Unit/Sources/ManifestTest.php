<?php

namespace Tests\Unit\Sources;

use App\Domain\Sources\FileClassifier;
use App\Domain\Sources\Manifest;
use App\Domain\Sources\ManifestEntry;
use App\Domain\Sources\SourceRejection;
use PHPUnit\Framework\TestCase;

class ManifestTest extends TestCase
{
    private function entry(string $path, string $content, ?string $excluded = null): ManifestEntry
    {
        return new ManifestEntry($path, strlen($content), $excluded ? null : hash('sha256', $content), FileClassifier::language($path), $excluded);
    }

    public function test_the_hash_ignores_order_and_excluded_files()
    {
        $a = new Manifest;
        $a->add($this->entry('routes/api.php', '<?php // api'));
        $a->add($this->entry('composer.json', '{}'));
        $a->add($this->entry('.env', 'SECRET=1', 'secret'));

        $b = new Manifest;
        $b->add($this->entry('composer.json', '{}'));
        $b->add($this->entry('.env', 'SECRET=2', 'secret'));
        $b->add($this->entry('routes/api.php', '<?php // api'));

        $this->assertSame($a->hash(), $b->hash());
        $this->assertSame(['.env', 'composer.json', 'routes/api.php'], array_map(fn ($e) => $e->path, $a->entries()));
        $this->assertCount(2, $a->stored());
    }

    public function test_the_hash_changes_with_content_or_path()
    {
        $a = new Manifest;
        $a->add($this->entry('a.php', 'x'));
        $b = new Manifest;
        $b->add($this->entry('a.php', 'y'));
        $c = new Manifest;
        $c->add($this->entry('b.php', 'x'));

        $this->assertNotSame($a->hash(), $b->hash());
        $this->assertNotSame($a->hash(), $c->hash());
    }

    public function test_case_only_collisions_are_rejected()
    {
        $m = new Manifest;
        $m->add($this->entry('app/User.php', 'a'));

        $this->expectException(SourceRejection::class);
        $m->add($this->entry('app/user.php', 'b'));
    }

    public function test_secrets_bulk_and_logs_are_excluded()
    {
        $max = 1024;
        foreach (['.env', 'config/.env.production', 'production.env', 'auth.json', 'credentials.json', '.npmrc', 'certs/server.pem', 'certs/client.p12', 'storage/oauth-private.key', 'deploy/id_rsa', 'deploy/id_ecdsa', '.git-credentials', 'home/.aws/credentials', 'service-account-prod.json'] as $p) {
            $this->assertSame('secret', FileClassifier::exclusionReason($p, 10, $max), $p);
        }
        $this->assertNull(FileClassifier::exclusionReason('.env.example', 10, $max));
        $this->assertSame('dependency_or_vcs', FileClassifier::exclusionReason('vendor/laravel/x.php', 10, $max));
        $this->assertSame('dependency_or_vcs', FileClassifier::exclusionReason('.git/config', 10, $max));
        $this->assertSame('dependency_or_vcs', FileClassifier::exclusionReason('myapp/vendor/x.php', 10, $max));
        $this->assertSame('dependency_or_vcs', FileClassifier::exclusionReason('.GIT/config', 10, $max));
        $this->assertSame('dependency_or_vcs', FileClassifier::exclusionReason('__MACOSX/a/._x.php', 10, $max));
        $this->assertNull(FileClassifier::exclusionReason('public/build/assets/app.js', 10, $max));
        // App folders that happen to be called "Vendor" are app code (Composer only writes lowercase vendor/).
        $this->assertNull(FileClassifier::exclusionReason('Vendor/x.php', 10, $max));
        $this->assertNull(FileClassifier::exclusionReason('app/Http/Controllers/Api/V1/Vendor/VendorController.php', 10, $max));
        $this->assertNull(FileClassifier::bulkFolder('app/Http/Controllers/Api/V1/Vendor/VendorController.php'));
        $this->assertSame('myapp/node_modules', FileClassifier::bulkFolder('myapp/node_modules/x/index.js'));
        $this->assertSame('myapp/public', FileClassifier::bulkFolder('myapp/public/build/app.js', ['myapp']));
        $this->assertNull(FileClassifier::bulkFolder('myapp/public/build/app.js', ['myapp/public']));
        $this->assertSame('log', FileClassifier::exclusionReason('storage/logs/laravel.log', 10, $max));
        $this->assertSame('log', FileClassifier::exclusionReason('myapp/storage/logs/today.txt', 10, $max));
        $this->assertSame('runtime_data', FileClassifier::exclusionReason('myapp/storage/framework/sessions/abc', 10, $max));
        $this->assertSame('database', FileClassifier::exclusionReason('database/database.sqlite', 10, $max));
        $this->assertSame('too_large', FileClassifier::exclusionReason('big.php', 2048, $max));
        $this->assertNull(FileClassifier::exclusionReason('app/Models/User.php', 10, $max));
        $this->assertSame('blade', FileClassifier::language('resources/views/a.blade.php'));
        $this->assertSame('php', FileClassifier::language('artisan'));
        $this->assertTrue(FileClassifier::looksBinary("PNG\0\0"));
    }
}
