<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\GitHubAppJwt;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\GitHubAppSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesGitHub;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class GitHubAppSettingsTest extends TestCase
{
    use FakesGitHub, RefreshDatabase, WorksWithWorkspaces;

    private User $admin;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.git_import' => true]);
        $this->withoutVite();
        Sleep::fake();
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logs[] = $e->message.json_encode($e->context));

        $this->admin = $this->memberOf(WorkspaceRole::Owner);
        $this->admin->forceFill(['is_platform_admin' => true])->save();
    }

    private function pem(): string
    {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);

        return $pem;
    }

    private function save(array $fields, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put('/settings/github-app', $fields + [
            'app_id' => '424242', 'slug' => 'api-engine', 'client_id' => 'Iv1.abc123',
        ]);
    }

    public function test_only_platform_admins_can_view_update_or_test_others_get_404()
    {
        $owner = $this->memberOf(WorkspaceRole::Owner); // a workspace owner is not a platform admin

        $this->actingAs($owner)->get('/settings/github-app')->assertNotFound();
        $this->save(['client_secret' => 's', 'private_key' => $this->pem()], $owner)->assertNotFound();
        $this->actingAs($owner)->post('/settings/github-app/test')->assertNotFound();
        $this->assertSame(0, GitHubAppSettings::query()->count());
        $this->get('/projects')->assertInertia(fn ($page) => $page->where('auth.isPlatformAdmin', false));

        $this->actingAs($this->admin)->get('/settings/github-app')->assertOk()->assertInertia(fn ($page) => $page
            ->component('settings/github-app')
            ->where('auth.isPlatformAdmin', true)
            ->where('settings.configured', false)
            ->where('urls.callback', url('/integrations/github/callback'))
            ->where('urls.homepage', url('/'))
            ->where('urls.newApp', 'https://github.com/settings/apps/new'));
    }

    public function test_secrets_are_encrypted_at_rest_write_only_and_never_sent_or_logged()
    {
        $pem = $this->pem();
        $this->save(['client_secret' => 'SUPER-CLIENT-SECRET', 'private_key' => $pem])->assertRedirect('/settings/github-app')->assertSessionHasNoErrors();

        $row = (array) DB::table('github_app_settings')->sole();
        $this->assertNotSame('SUPER-CLIENT-SECRET', $row['client_secret']);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $row['private_key']);
        $settings = GitHubAppSettings::current();
        $this->assertSame('SUPER-CLIENT-SECRET', $settings?->client_secret);
        $this->assertSame(trim($pem), trim((string) $settings?->private_key));
        $this->assertTrue($settings?->isComplete());

        $page = $this->get('/settings/github-app')->assertInertia(fn ($page) => $page
            ->where('settings.appId', '424242')
            ->where('settings.clientSecretSet', true)
            ->where('settings.privateKeySet', true)
            ->where('settings.configured', true)
            ->where('settings.updatedBy', $this->admin->name)
            ->where('urls.publicPage', 'https://github.com/apps/api-engine')
            ->missing('settings.clientSecret')
            ->missing('settings.privateKey'));
        $html = (string) $page->getContent();
        $audit = json_encode(AuditEvent::query()->where('event_type', 'github_app.settings_updated')->sole()->metadata);
        $sessionDump = json_encode(session()->all());

        foreach (['SUPER-CLIENT-SECRET', 'PRIVATE KEY', substr(explode("\n", $pem)[1], 0, 40)] as $secret) {
            $this->assertStringNotContainsString($secret, $html.implode("\n", $this->logs).$audit.$sessionDump, $secret);
        }
        $this->assertStringContainsString('client_secret', (string) $audit); // which fields changed, never their values
    }

    public function test_blank_secret_fields_keep_the_stored_values_and_a_pem_file_can_be_uploaded()
    {
        $pem = $this->pem();
        $this->save(['client_secret' => 'FIRST-SECRET', 'private_key' => $pem]);

        $this->save(['client_secret' => '', 'private_key' => '', 'slug' => 'renamed-app'])->assertSessionHasNoErrors();
        $settings = GitHubAppSettings::current();
        $this->assertSame('renamed-app', $settings?->slug);
        $this->assertSame('FIRST-SECRET', $settings?->client_secret);
        $this->assertSame(trim($pem), trim((string) $settings?->private_key));

        $newPem = $this->pem();
        $this->save(['private_key_file' => UploadedFile::fake()->createWithContent('api-engine.2026-11-24.private-key.pem', $newPem)])->assertSessionHasNoErrors();
        $this->assertSame(trim($newPem), trim((string) GitHubAppSettings::current()?->private_key));
        $this->assertSame(1, GitHubAppSettings::query()->count());
    }

    public function test_invalid_input_is_rejected_without_echoing_the_key()
    {
        $this->save(['client_secret' => 's', 'private_key' => "-----BEGIN PRIVATE KEY-----\nNOT-A-REAL-KEY-XYZ\n-----END PRIVATE KEY-----"])
            ->assertSessionHasErrors('private_key');
        $this->assertStringNotContainsString('NOT-A-REAL-KEY-XYZ', json_encode(session('errors')?->getBag('default')->all()).json_encode(session()->all()).implode('', $this->logs));

        $this->save(['client_secret' => '', 'private_key' => ''])->assertSessionHasErrors(['client_secret', 'private_key']); // nothing stored yet
        $this->save(['app_id' => 'abc', 'slug' => 'bad slug!', 'client_id' => 'x y', 'client_secret' => 's', 'private_key' => $this->pem()])
            ->assertSessionHasErrors(['app_id', 'slug', 'client_id']);
        $this->assertSame(0, GitHubAppSettings::query()->count());
    }

    public function test_the_jwt_service_reads_the_saved_settings()
    {
        $pem = $this->pem();
        $this->save(['app_id' => '777', 'client_secret' => 's', 'private_key' => $pem]);

        [, $payload, $signature] = explode('.', $jwt = app(GitHubAppJwt::class)->make());
        $public = openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'];
        $this->assertSame(1, openssl_verify(implode('.', array_slice(explode('.', $jwt), 0, 2)), (string) base64_decode(strtr($signature, '-_', '+/')), $public, OPENSSL_ALGO_SHA256));
        $this->assertSame('777', json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true)['iss']);
    }

    public function test_test_connection_reports_the_app_or_a_stable_error()
    {
        $this->configureGitHubApp();
        $this->fakeGitHub();

        $this->actingAs($this->admin)->post('/settings/github-app/test')->assertRedirect('/settings/github-app')
            ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'Connected to the GitHub App “API Engine Test” (api-engine-test).']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.github.com/app' && str_starts_with($r->header('Authorization')[0], 'Bearer ey'));

        $this->gh['fail'] = ['#^/app$#' => 401];
        $this->post('/settings/github-app/test')->assertSessionHas('inertia.flash_data.toast.type', 'error');

        DB::table('github_app_settings')->delete();
        $this->post('/settings/github-app/test')->assertSessionHas('inertia.flash_data.toast.type', 'error');
    }

    public function test_readiness_reports_only_whether_github_is_configured()
    {
        $this->actingAs($this->admin)->getJson('/ready')->assertJsonPath('github_configured', false);

        $this->configureGitHubApp();
        $response = $this->getJson('/ready')->assertJsonPath('github_configured', true);
        $this->assertStringNotContainsString(self::APP_ID, (string) $response->getContent());
        $this->assertStringNotContainsString('PRIVATE', (string) $response->getContent());
    }
}
