<?php

namespace Tests\Feature\GitHub;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\GitHubConnectionAttempt;
use App\Models\GitHubInstallation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesGitHub;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class GitHubConnectionTest extends TestCase
{
    use FakesGitHub, RefreshDatabase, WorksWithWorkspaces;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true, 'features.source_snapshots' => true, 'features.git_import' => true]);
        $this->withoutVite();
        Sleep::fake();
        $this->configureGitHubApp();
        $this->fakeGitHub();

        $this->workspace = Workspace::factory()->create();
        $this->owner = $this->memberOf(WorkspaceRole::Owner, $this->workspace);
    }

    /** Starts a connection as $user and returns the raw state GitHub would send back. */
    private function start(?User $user = null): string
    {
        $response = $this->actingAs($user ?? $this->owner)->post("/workspaces/{$this->workspace->id}/integrations/github/connect");
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/apps/api-engine-test/installations/new?state=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function returnFromGitHub(string $state, array $extra = [])
    {
        return $this->get('/integrations/github/callback?'.http_build_query($extra + ['state' => $state, 'code' => 'one-time-code', 'installation_id' => 501, 'setup_action' => 'install']));
    }

    /** @return iterable<string, array{WorkspaceRole, bool}> */
    public static function roles(): iterable
    {
        yield 'owner' => [WorkspaceRole::Owner, true];
        yield 'maintainer' => [WorkspaceRole::Maintainer, true];
        yield 'developer' => [WorkspaceRole::Developer, false];
        yield 'qa' => [WorkspaceRole::QA, false];
        yield 'viewer' => [WorkspaceRole::Viewer, false];
    }

    #[DataProvider('roles')]
    public function test_only_owners_and_maintainers_can_start_a_connection(WorkspaceRole $role, bool $allowed)
    {
        $user = $this->memberOf($role, $this->workspace);
        $response = $this->actingAs($user)->post("/workspaces/{$this->workspace->id}/integrations/github/connect");

        $allowed ? $response->assertRedirect() : $response->assertForbidden();
        $this->assertSame($allowed ? 1 : 0, GitHubConnectionAttempt::query()->count());
    }

    public function test_an_inertia_visit_gets_an_external_location_and_non_members_get_404()
    {
        $this->actingAs($this->owner)->post("/workspaces/{$this->workspace->id}/integrations/github/connect", [], ['X-Inertia' => 'true'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location');

        $stranger = $this->memberOf(WorkspaceRole::Owner);
        $this->actingAs($stranger)->post("/workspaces/{$this->workspace->id}/integrations/github/connect")->assertNotFound();
        $this->actingAs($stranger)->get("/workspaces/{$this->workspace->id}/integrations/github")->assertNotFound();
    }

    public function test_a_verified_callback_links_the_installation_to_the_starting_workspace_only()
    {
        $state = $this->start();
        $this->assertSame(hash('sha256', $state), GitHubConnectionAttempt::query()->sole()->state_hash); // only the hash is stored
        $this->assertFalse(GitHubConnectionAttempt::query()->where('state_hash', $state)->exists());

        $this->returnFromGitHub($state)->assertRedirect("/workspaces/{$this->workspace->id}/integrations/github");

        $installation = GitHubInstallation::query()->sole();
        $this->assertSame(501, $installation->github_installation_id);
        $this->assertSame('acme', $installation->account_login);
        $this->assertSame('Organization', $installation->account_type);
        $this->assertTrue($installation->isLinkedTo($this->workspace->id));
        $this->assertSame(1, DB::table('workspace_github_installations')->count());
        $this->assertNotNull(GitHubConnectionAttempt::query()->sole()->consumed_at);

        $audit = AuditEvent::query()->where('event_type', 'github.installation_connected')->sole();
        $this->assertEquals(['installation_id' => 501, 'account_login' => 'acme'], $audit->metadata); // MySQL reorders JSON keys

        // The user token and the code were used once and never kept.
        $stored = json_encode([DB::table('github_installations')->get(), DB::table('github_connection_attempts')->get(), DB::table('audit_events')->get()]);
        $this->assertStringNotContainsString(self::USER_TOKEN, (string) $stored);
        $this->assertStringNotContainsString('one-time-code', (string) $stored);

        $this->get("/workspaces/{$this->workspace->id}/integrations/github")->assertInertia(fn ($page) => $page
            ->component('workspaces/integrations/github')
            ->where('installations.0.accountLogin', 'acme')
            ->where('installations.0.manageUrl', 'https://github.com/organizations/acme/settings/installations/501'));
    }

    public function test_the_callback_refuses_missing_expired_consumed_and_foreign_state()
    {
        $this->actingAs($this->owner);
        $this->returnFromGitHub('')->assertRedirect();
        $this->returnFromGitHub('not-a-real-state')->assertRedirect();

        $state = $this->start();
        $this->returnFromGitHub($state)->assertRedirect();
        $this->returnFromGitHub($state); // consumed: refused the second time
        $this->assertSame(1, DB::table('workspace_github_installations')->count());
        DB::table('workspace_github_installations')->delete();

        $expired = $this->start();
        $this->travel(11)->minutes();
        $this->returnFromGitHub($expired)->assertSessionHas('inertia.flash_data.toast.type', 'error');
        $this->travelBack();

        // Another user (even a co-owner) can't finish someone else's flow.
        $mine = $this->start();
        $coOwner = $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $this->actingAs($coOwner);
        $this->returnFromGitHub($mine);

        $this->assertSame(0, DB::table('workspace_github_installations')->count());
    }

    public function test_a_spoofed_installation_id_the_user_cannot_see_is_refused()
    {
        $state = $this->start();
        $this->gh['user_installations'] = [999]; // the GitHub user can't see installation 501

        $this->returnFromGitHub($state)->assertRedirect();

        $this->assertSame(0, GitHubInstallation::query()->count());
        $this->assertSame(0, DB::table('workspace_github_installations')->count());
    }

    public function test_an_installation_of_another_github_app_is_refused()
    {
        $state = $this->start();
        $this->gh['user_installation_app_id'] = 1;

        $this->returnFromGitHub($state);

        $this->assertSame(0, DB::table('workspace_github_installations')->count());
    }

    public function test_a_user_who_lost_manage_integrations_in_the_starting_workspace_is_refused()
    {
        $maintainer = $this->memberOf(WorkspaceRole::Maintainer, $this->workspace);
        $state = $this->start($maintainer);
        $this->membershipOf($maintainer, $this->workspace)->forceFill(['role' => WorkspaceRole::Developer])->save();

        $this->actingAs($maintainer)->returnFromGitHub($state);

        $this->assertSame(0, DB::table('workspace_github_installations')->count());
    }

    public function test_denied_authorization_and_missing_installation_ids_are_refused()
    {
        $this->returnFromGitHub($this->start(), ['error' => 'access_denied', 'code' => null])->assertSessionHas('inertia.flash_data.toast.type', 'error');
        $this->returnFromGitHub($this->start(), ['installation_id' => 'abc']);
        $this->returnFromGitHub($this->start(), ['installation_id' => null]);

        $this->assertSame(0, GitHubInstallation::query()->count());
    }

    public function test_two_workspaces_with_their_own_installations_never_see_each_other()
    {
        $this->returnFromGitHub($this->start());

        $other = Workspace::factory()->create();
        $otherOwner = $this->memberOf(WorkspaceRole::Owner, $other);
        $this->gh['installations'][702] = ['repos' => [9002 => 'stackfood/api']];
        $this->gh['user_installations'] = [702];
        $state = $this->actingAs($otherOwner)->post("/workspaces/{$other->id}/integrations/github/connect")->headers->get('Location');
        parse_str((string) parse_url((string) $state, PHP_URL_QUERY), $query);
        $this->returnFromGitHub((string) $query['state'], ['installation_id' => 702]);

        $this->get("/workspaces/{$other->id}/integrations/github")->assertInertia(fn ($page) => $page->has('installations', 1)->where('installations.0.githubId', 702));
        $this->actingAs($this->owner)->get("/workspaces/{$this->workspace->id}/integrations/github")->assertInertia(fn ($page) => $page->has('installations', 1)->where('installations.0.githubId', 501));

        $theirs = GitHubInstallation::query()->where('github_installation_id', 702)->sole();
        $this->actingAs($this->owner)->delete("/workspaces/{$this->workspace->id}/integrations/github/{$theirs->id}")->assertNotFound();
        $this->assertTrue($theirs->isLinkedTo($other->id));
    }

    public function test_connect_without_app_settings_is_a_stable_not_configured_error()
    {
        DB::table('github_app_settings')->delete();

        $this->actingAs($this->owner)->postJson("/workspaces/{$this->workspace->id}/integrations/github/connect")
            ->assertStatus(503)->assertJsonPath('error.code', 'github.not_configured');
        $this->get("/workspaces/{$this->workspace->id}/integrations/github")->assertInertia(fn ($page) => $page->where('configured', false));
    }
}
