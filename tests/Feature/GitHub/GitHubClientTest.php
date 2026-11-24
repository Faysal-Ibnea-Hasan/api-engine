<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\GitHubApiClient;
use App\Domain\GitHub\GitHubAppJwt;
use App\Domain\GitHub\GitHubError;
use App\Domain\GitHub\GitHubInstallationTokens;
use App\Domain\GitHub\GitHubRepositories;
use App\Models\GitHubAppSettings;
use App\Models\GitHubInstallation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Concerns\FakesGitHub;
use Tests\TestCase;

class GitHubClientTest extends TestCase
{
    use FakesGitHub, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->configureGitHubApp();
    }

    /** @return array<string, mixed> */
    private function claims(string $jwt): array
    {
        [$header, $payload, $signature] = explode('.', $jwt);
        $decode = fn (string $s) => (string) base64_decode(strtr($s, '-_', '+/'));
        $public = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKeyPem))['key'];

        $this->assertSame(1, openssl_verify("{$header}.{$payload}", $decode($signature), $public, OPENSSL_ALGO_SHA256));
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($decode($header), true));

        return json_decode($decode($payload), true);
    }

    public function test_the_app_jwt_is_rs256_signed_with_the_stored_key_and_has_a_bounded_lifetime()
    {
        $claims = $this->claims(app(GitHubAppJwt::class)->make());

        $this->assertSame((int) self::APP_ID, $claims['iss']);
        $this->assertLessThanOrEqual(now()->getTimestamp() - 30, $claims['iat']); // allows clock drift
        $this->assertLessThanOrEqual(600, $claims['exp'] - now()->getTimestamp());
        $this->assertGreaterThan(now()->getTimestamp(), $claims['exp']);
    }

    public function test_missing_or_broken_settings_fail_safely_without_echoing_the_key()
    {
        GitHubAppSettings::query()->update(['private_key' => encrypt('-----BEGIN PRIVATE KEY-----\nGARBAGE-KEY-MATERIAL\n-----END PRIVATE KEY-----', false)]);

        try {
            app(GitHubAppJwt::class)->make();
            $this->fail('expected not_configured');
        } catch (GitHubError $e) {
            $this->assertSame('github.not_configured', $e->errorCode());
            $this->assertStringNotContainsString('GARBAGE', $e->getMessage());
        }

        DB::table('github_app_settings')->delete();
        $this->expectExceptionObject(GitHubError::of('github.not_configured'));
        app(GitHubAppJwt::class)->make();
    }

    public function test_installation_tokens_are_minted_read_only_memoized_below_expiry_and_never_stored()
    {
        $this->fakeGitHub();
        $installation = GitHubInstallation::factory()->create(['github_installation_id' => 501]);
        $tokens = app(GitHubInstallationTokens::class);

        $this->assertSame(self::INSTALLATION_TOKEN, $tokens->token($installation, 9001));
        $this->assertSame(self::INSTALLATION_TOKEN, $tokens->token($installation, 9001));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.github.com/app/installations/501/access_tokens'
            && $r->data() === ['permissions' => ['contents' => 'read', 'metadata' => 'read'], 'repository_ids' => [9001]]
            && str_starts_with($r->header('Authorization')[0], 'Bearer ey')
            && $r->header('Accept')[0] === 'application/vnd.github+json'
            && $r->header('X-GitHub-Api-Version')[0] === GitHubApiClient::API_VERSION);

        $this->travel(59)->minutes(); // within a minute of GitHub's expiry: mint a fresh one
        $tokens->token($installation, 9001);
        Http::assertSentCount(2);

        $this->assertStringNotContainsString(self::INSTALLATION_TOKEN, json_encode(DB::table('cache')->get()).json_encode(DB::table('github_installations')->get()));
    }

    public function test_a_401_mints_a_fresh_token_once_then_gives_up()
    {
        $this->fakeGitHub();
        $installation = GitHubInstallation::factory()->create(['github_installation_id' => 501]);
        $this->gh['fail'] = ['#^/installation/repositories$#' => 401];

        try {
            app(GitHubRepositories::class)->list($installation, (string) Str::uuid());
            $this->fail('expected an error');
        } catch (GitHubError $e) {
            $this->assertSame('github.permission_revoked', $e->errorCode());
            $this->assertStringNotContainsString(self::INSTALLATION_TOKEN, $e->getMessage());
        }

        $tokenCalls = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/access_tokens'))->count();
        $this->assertSame(2, $tokenCalls);
    }

    public function test_the_redirect_allowlist_accepts_only_github_archive_hosts_over_https()
    {
        $client = app(GitHubApiClient::class);
        $client->assertAllowed('https://api.github.com/repos/a/b/zipball/x', archive: true);
        $client->assertAllowed('https://codeload.github.com/a/b/legacy.zip/x?token=t', archive: true);

        foreach ([
            'https://codeload.github.com/x' => false,           // archive host, but not for API calls
            'http://codeload.github.com/x' => true,
            'https://codeload.github.com.evil.com/x' => true,
            'https://evil.com/x' => true,
            'https://user@codeload.github.com/x' => true,
            'https://codeload.github.com:8443/x' => true,
            'file:///etc/passwd' => true,
            'ftp://api.github.com/x' => true,
        ] as $url => $archive) {
            try {
                $client->assertAllowed($url, archive: $archive);
                $this->fail("accepted {$url}");
            } catch (GitHubError $e) {
                $this->assertSame('github.archive_download_failed', $e->errorCode());
            }
        }
    }

    public function test_refs_are_bounded_and_reject_traversal()
    {
        foreach (['main', 'feature/x', 'v1.0.0', 'release-2026.11'] as $ok) {
            $this->assertTrue(GitHubRepositories::validRef($ok), $ok);
        }
        foreach (['', '../x', 'a/../b', '-rf', '/abs', 'a b', 'a~1', 'a^', 'a:b', 'x.lock', 'a//b', 'a@{1}', str_repeat('a', 256), "a\nb"] as $bad) {
            $this->assertFalse(GitHubRepositories::validRef($bad), $bad);
        }
    }
}
