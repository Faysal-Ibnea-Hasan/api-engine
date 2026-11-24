<?php

namespace Tests\Concerns;

use App\Models\GitHubAppSettings;
use App\Models\GitHubInstallation;
use App\Models\GitHubRepositorySource;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * A fake GitHub (Http::fake + preventStrayRequests): GitHub is never contacted. Tests change
 * $this->gh to move branches, revoke access, or make an endpoint fail.
 */
trait FakesGitHub
{
    public const INSTALLATION_TOKEN = 'ghs_INSTALLATION-TOKEN-NEVER-STORE';

    public const USER_TOKEN = 'ghu_USER-TOKEN-NEVER-STORE';

    public const CODELOAD_TOKEN = 'CODELOAD-SIGNED-URL-TOKEN';

    public const APP_ID = '424242';

    public const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @var array<string, mixed> */
    protected array $gh = [];

    protected string $privateKeyPem = '';

    protected function configureGitHubApp(): GitHubAppSettings
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->privateKeyPem = $pem;

        $settings = new GitHubAppSettings;
        $settings->forceFill([
            'app_id' => self::APP_ID,
            'slug' => 'api-engine-test',
            'client_id' => 'Iv1.testclient',
            'client_secret' => 'CLIENT-SECRET-NEVER-SHOW',
            'private_key' => $pem,
        ])->save();

        return $settings;
    }

    /** An installation linked to $workspace, and a project source pointing at repository $repoId. */
    protected function linkedRepository(Project $project, int $installationId = 501, int $repoId = 9001, string $fullName = 'acme/api'): GitHubRepositorySource
    {
        $installation = GitHubInstallation::query()->where('github_installation_id', $installationId)->first()
            ?? GitHubInstallation::factory()->create(['github_installation_id' => $installationId, 'account_login' => explode('/', $fullName)[0]]);
        $this->link($project->workspace, $installation);
        [$owner, $name] = explode('/', $fullName);

        return GitHubRepositorySource::factory()->create([
            'project_id' => $project->id,
            'github_installation_id' => $installation->id,
            'github_repository_id' => $repoId,
            'owner_login' => $owner,
            'repository_name' => $name,
            'full_name' => $fullName,
        ]);
    }

    protected function link(Workspace $workspace, GitHubInstallation $installation): void
    {
        $workspace->githubInstallations()->syncWithoutDetaching([$installation->id]);
    }

    /**
     * @param  array<string, string>  $files  repository files (paths relative to the repo root)
     */
    protected function fakeGitHub(array $files = [], array $state = []): void
    {
        $this->gh = $state + [
            'installations' => [501 => ['repos' => [9001 => 'acme/api']]],
            'refs' => ['main' => self::SHA_A, 'v1.0.0' => self::SHA_A, self::SHA_A => self::SHA_A, self::SHA_B => self::SHA_B],
            'archives' => [self::SHA_A => $files, self::SHA_B => $files],
            'user_installations' => [501],
            'fail' => [], // path regex => status
            'redirect_to' => null, // override the zipball redirect target
            'calls' => 0,
        ];

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->answer($request));
    }

    private function answer(Request $request): mixed
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $this->gh['calls']++;

        foreach ($this->gh['fail'] as $pattern => $status) {
            if (preg_match($pattern, $path) === 1) {
                return Http::response(['message' => 'fail '.self::INSTALLATION_TOKEN], $status, $status === 403 ? ['x-ratelimit-remaining' => '1'] : []);
            }
        }

        // An installation removed on GitHub: its tokens stop working at once.
        if (($this->gh['revoked'] ?? false) && $request->hasHeader('Authorization', 'Bearer '.self::INSTALLATION_TOKEN)) {
            return Http::response(['message' => 'Bad credentials'], 401);
        }

        if ($host === 'github.com' && $path === '/login/oauth/access_token') {
            return Http::response(['access_token' => self::USER_TOKEN, 'token_type' => 'bearer']);
        }

        if ($host === 'codeload.github.com') {
            $this->assertFalse($request->hasHeader('Authorization'), 'the installation token must never go to the archive host');
            $sha = basename($path);

            return Http::response($this->zipBytes($this->gh['archives'][$sha] ?? [], 'acme-api-'.substr($sha, 0, 7)), 200, ['Content-Type' => 'application/zip']);
        }

        $this->assertSame('api.github.com', $host);

        if (preg_match('#^/app/installations/(\d+)/access_tokens$#', $path, $m) === 1) {
            $installation = $this->gh['installations'][(int) $m[1]] ?? null;
            if ($installation === null) {
                return Http::response(['message' => 'Not Found'], 404);
            }
            foreach ($request->data()['repository_ids'] ?? [] as $repoId) {
                if (! isset($installation['repos'][$repoId])) {
                    return Http::response(['message' => 'There is at least one repository that does not exist or is not accessible'], 422);
                }
            }

            return Http::response(['token' => self::INSTALLATION_TOKEN, 'expires_at' => now()->addHour()->toIso8601String()], 201);
        }

        if (preg_match('#^/app/installations/(\d+)$#', $path, $m) === 1) {
            return Http::response(['id' => (int) $m[1], 'account' => ['id' => 77, 'login' => 'acme', 'type' => 'Organization'], 'repository_selection' => 'selected', 'permissions' => ['contents' => 'read', 'metadata' => 'read'], 'suspended_at' => null]);
        }

        if ($path === '/app') {
            return Http::response(['id' => (int) self::APP_ID, 'name' => 'API Engine Test', 'slug' => 'api-engine-test']);
        }

        if ($path === '/user/installations') {
            return Http::response(['total_count' => 1, 'installations' => array_map(fn (int $id) => ['id' => $id, 'app_id' => $this->gh['user_installation_app_id'] ?? (int) self::APP_ID], $this->gh['user_installations'])]);
        }

        if ($path === '/installation/repositories') {
            $repos = [];
            foreach ($this->gh['installations'] as $installation) {
                foreach ($installation['repos'] as $id => $name) {
                    $repos[] = $this->repo($id, $name);
                }
            }

            return Http::response(['total_count' => count($repos), 'repositories' => $repos]);
        }

        if (preg_match('#^/repositories/(\d+)$#', $path, $m) === 1) {
            foreach ($this->gh['installations'] as $installation) {
                if (isset($installation['repos'][(int) $m[1]])) {
                    return Http::response($this->repo((int) $m[1], $installation['repos'][(int) $m[1]]));
                }
            }

            return Http::response(['message' => 'Not Found'], 404);
        }

        if (preg_match('#^/repos/[^/]+/[^/]+/commits/(.+)$#', $path, $m) === 1) {
            $sha = $this->gh['refs'][rawurldecode($m[1])] ?? null;

            return $sha === null
                ? Http::response(['message' => 'No commit found'], 422)
                : Http::response(['sha' => $sha, 'commit' => ['message' => "Ship it\n\nbody", 'committer' => ['date' => '2026-11-20T10:00:00Z']]]);
        }

        if (preg_match('#^/repos/[^/]+/[^/]+/(branches|tags)$#', $path, $m) === 1) {
            return Http::response($m[1] === 'branches' ? [['name' => 'main'], ['name' => 'feature/x']] : [['name' => 'v1.0.0']]);
        }

        if (preg_match('#^/repos/([^/]+)/([^/]+)/zipball/([0-9a-f]{40})$#', $path, $m) === 1) {
            return Http::response('', 302, ['Location' => $this->gh['redirect_to'] ?? "https://codeload.github.com/{$m[1]}/{$m[2]}/legacy.zip/{$m[3]}?token=".self::CODELOAD_TOKEN]);
        }

        $this->fail("Unexpected GitHub call: {$request->method()} {$url}");
    }

    /** @return array<string, mixed> */
    private function repo(int $id, string $fullName): array
    {
        return ['id' => $id, 'full_name' => $fullName, 'private' => true, 'visibility' => 'private', 'default_branch' => 'main', 'archived' => false, 'disabled' => false];
    }

    /** A GitHub-style zipball: every file inside one generated "owner-repo-sha7/" folder. */
    protected function zipBytes(array $files, string $wrapper = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gh-zip-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($wrapper !== '') {
            $zip->addEmptyDir($wrapper);
        }
        foreach ($files as $name => $content) {
            $zip->addFromString(($wrapper === '' ? '' : "{$wrapper}/").$name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
