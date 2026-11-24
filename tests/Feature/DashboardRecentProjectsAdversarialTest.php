<?php

namespace Tests\Feature;

use App\Domain\Workspaces\WorkspaceRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

// Adversary chunk 3 (revision 10): the dashboard recentProjects prop must never
// show a row that /projects would not list for the same user and workspace.
class DashboardRecentProjectsAdversarialTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private const KEYS = ['id', 'name', 'slug', 'status', 'visibility', 'setupComplete', 'updatedAt'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        config(['features.project_wizard' => true]);
        $this->withoutVite();
    }

    /** @return list<string> */
    private function dashboardIds(User $user, string $query = ''): array
    {
        $ids = null;
        $this->actingAs($user)->get('/dashboard'.$query)->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$ids) {
                $page->component('dashboard')->has('recentProjects');
                $ids = collect($page->toArray()['props']['recentProjects'])->pluck('id')->all();
            });

        return $ids;
    }

    /** @return list<string> */
    private function indexIds(User $user): array
    {
        $ids = null;
        $this->actingAs($user)->get('/projects')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$ids) {
                $page->component('projects/index');
                $ids = collect($page->toArray()['props']['projects'])->pluck('id')->all();
            });

        return $ids;
    }

    private function project(Workspace $workspace, int $minutesAgo, array $attributes = [], ?string $state = null): Project
    {
        $factory = Project::factory()->for($workspace);
        $factory = $state === null ? $factory : $factory->{$state}();

        return $factory->create($attributes + ['updated_at' => now()->subMinutes($minutesAgo)]);
    }

    public function test_every_role_sees_exactly_the_first_five_rows_projects_would_list()
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();
        $users = collect(WorkspaceRole::cases())->mapWithKeys(fn (WorkspaceRole $r) => [$r->value => $this->memberOf($r, $workspace)]);
        $stranger = $this->memberOf(WorkspaceRole::Owner, $workspace);

        // Newest first: hidden rows sit above the visible ones, so a missing
        // filter would push them into the top five.
        $this->project($other, 0);
        $secret = $this->project($workspace, 1, ['created_by' => $stranger->id], 'restricted');
        $this->project($workspace, 2, [], 'archived');
        $this->project($workspace, 3, ['created_by' => $users['viewer']->id], 'restricted');
        $this->project($workspace, 4, ['created_by' => $users['qa']->id, 'visibility' => 'restricted'], 'archived');
        foreach (range(5, 11) as $i) {
            $this->project($workspace, $i);
        }

        foreach ($users as $role => $user) {
            $index = $this->indexIds($user);
            $this->assertSame(array_slice($index, 0, 5), $this->dashboardIds($user), "role $role");
            if (in_array($role, ['developer', 'qa', 'viewer'], true)) {
                $this->assertNotContains($secret->id, $this->dashboardIds($user), "role $role");
            }
        }
    }

    public function test_the_role_comes_from_the_current_workspace_not_another_membership()
    {
        $a = Workspace::factory()->create();
        $b = Workspace::factory()->create();
        $user = $this->memberOf(WorkspaceRole::Owner, $a);
        WorkspaceMember::factory()->for($b)->for($user)->role(WorkspaceRole::Viewer)->create();
        $author = $this->memberOf(WorkspaceRole::Owner, $b);

        $inA = $this->project($a, 1);
        $secretB = $this->project($b, 0, ['created_by' => $author->id], 'restricted');
        $openB = $this->project($b, 2);

        $user->switchWorkspace($a->id);
        $this->assertSame([$inA->id], $this->dashboardIds($user));

        // Owner in A must not carry over: as Viewer in B the restricted row stays hidden.
        $user->switchWorkspace($b->id);
        $this->assertSame([$openB->id], $this->dashboardIds($user));
        $this->assertNotContains($secretB->id, $this->dashboardIds($user));
    }

    public function test_a_restricted_project_created_in_another_workspace_does_not_leak()
    {
        $a = Workspace::factory()->create();
        $b = Workspace::factory()->create();
        $user = $this->memberOf(WorkspaceRole::Viewer, $a);
        WorkspaceMember::factory()->for($b)->for($user)->role(WorkspaceRole::Developer)->create();

        $mineInB = $this->project($b, 0, ['created_by' => $user->id], 'restricted');
        $inA = $this->project($a, 1);

        $user->switchWorkspace($a->id);
        $ids = $this->dashboardIds($user);

        $this->assertSame([$inA->id], $ids);
        $this->assertNotContains($mineInB->id, $ids);
    }

    public function test_a_revoked_current_workspace_falls_back_to_a_live_membership()
    {
        $a = Workspace::factory()->create();
        $b = Workspace::factory()->create();
        $user = $this->memberOf(WorkspaceRole::Developer, $a);
        WorkspaceMember::factory()->for($b)->for($user)->role(WorkspaceRole::Owner)->create();
        $inA = $this->project($a, 1);
        $this->project($b, 0);
        $this->project($b, 2, [], 'restricted');

        $user->switchWorkspace($b->id);
        $this->membershipOf($user, $b)->delete();

        $this->assertSame([$inA->id], $this->dashboardIds($user->fresh()));
    }

    public function test_a_current_workspace_the_user_never_joined_shows_none_of_its_rows()
    {
        $a = Workspace::factory()->create();
        $foreign = Workspace::factory()->create();
        $user = $this->memberOf(WorkspaceRole::Viewer, $a);
        $inA = $this->project($a, 1);
        $this->project($foreign, 0);

        $user->forceFill(['current_workspace_id' => $foreign->id])->save();

        $this->assertSame([$inA->id], $this->dashboardIds($user));
    }

    public function test_a_stale_current_workspace_with_no_membership_left_gets_no_prop()
    {
        $foreign = Workspace::factory()->create();
        $this->project($foreign, 0);
        $user = User::factory()->create();
        $user->forceFill(['current_workspace_id' => $foreign->id])->save();

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('dashboard')->missing('recentProjects'));
    }

    public function test_an_archived_newest_project_never_displaces_the_five_live_ones()
    {
        $workspace = Workspace::factory()->create();
        $owner = $this->memberOf(WorkspaceRole::Owner, $workspace);
        $this->project($workspace, 0, [], 'archived');
        $this->project($workspace, 1, ['visibility' => 'restricted'], 'archived');
        $live = collect(range(2, 6))->map(fn (int $i) => $this->project($workspace, $i)->id)->all();

        $this->assertSame($live, $this->dashboardIds($owner));
    }

    public function test_query_string_keys_do_not_change_the_rows()
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();
        $viewer = $this->memberOf(WorkspaceRole::Viewer, $workspace);
        $this->project($workspace, 0, [], 'archived');
        $this->project($workspace, 1, [], 'restricted');
        $this->project($other, 2);
        foreach (range(3, 9) as $i) {
            $this->project($workspace, $i);
        }

        $plain = $this->dashboardIds($viewer);
        $query = '?'.http_build_query([
            'archived' => 1,
            'q' => 'zzz',
            'workspace' => $other->id,
            'workspace_id' => $other->id,
            'current_workspace_id' => $other->id,
            'limit' => 50,
            'role' => 'owner',
        ]);

        $this->assertCount(5, $plain);
        $this->assertSame($plain, $this->dashboardIds($viewer, $query));
        $this->assertSame($workspace->id, $viewer->fresh()->current_workspace_id);
    }

    public function test_every_row_has_exactly_the_seven_summary_keys()
    {
        $workspace = Workspace::factory()->create();
        $owner = $this->memberOf(WorkspaceRole::Owner, $workspace);
        $this->project($workspace, 0, ['description' => 'secret notes', 'created_by' => $owner->id], 'restricted');
        $this->project($workspace, 1, ['wizard_completed_at' => now()]);
        $this->project($workspace, 2);

        $this->actingAs($owner)->get('/dashboard')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->has('recentProjects', 3);
                foreach (range(0, 2) as $i) {
                    $page->has("recentProjects.$i", fn (AssertableInertia $row) => $row->hasAll(self::KEYS));
                }
            })
            ->assertDontSee('secret notes');
    }

    public function test_a_project_with_no_updated_at_still_renders()
    {
        $workspace = Workspace::factory()->create();
        $owner = $this->memberOf(WorkspaceRole::Owner, $workspace);
        $project = $this->project($workspace, 0);
        DB::table('projects')->where('id', $project->id)->update(['updated_at' => null]);

        $this->actingAs($owner)->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentProjects', 1)
                ->where('recentProjects.0.updatedAt', null));
    }

    public function test_both_flags_off_gives_no_prop_for_any_role()
    {
        $workspace = Workspace::factory()->create();
        $this->project($workspace, 0);
        config(['features.project_wizard' => false, 'features.workspace_management' => false]);

        foreach ([WorkspaceRole::Owner, WorkspaceRole::Viewer] as $role) {
            $this->actingAs($this->memberOf($role, $workspace))->get('/dashboard')->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component('dashboard')->missing('recentProjects'));
        }
    }

    public function test_a_partial_reload_cannot_force_the_prop_when_a_flag_is_off()
    {
        $workspace = Workspace::factory()->create();
        $viewer = $this->memberOf(WorkspaceRole::Viewer, $workspace);
        $this->project($workspace, 0);
        config(['features.project_wizard' => false]);

        $version = app(HandleInertiaRequests::class)->version(request());
        $this->actingAs($viewer)->get('/dashboard', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'recentProjects',
        ])->assertOk()->assertJsonMissingPath('props.recentProjects');
    }

    public function test_guests_and_unverified_users_never_reach_the_list()
    {
        $this->get('/dashboard')->assertRedirect(route('login'));

        $workspace = Workspace::factory()->create();
        $user = $this->memberOf(WorkspaceRole::Owner, $workspace);
        $user->forceFill(['email_verified_at' => null])->save();
        $this->project($workspace, 0);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }
}
