<?php

namespace Tests\Feature\Workspaces;

use App\Domain\Workspaces\WorkspaceRole;
use App\Domain\Workspaces\WorkspaceService;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class WorkspaceLifecycleTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    public function test_routes_are_hidden_while_the_feature_is_off()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/workspaces')->assertNotFound();
        $this->post('/workspaces', ['name' => 'Acme'])->assertNotFound();
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->where('workspaces', null)
            ->where('currentWorkspace', null));
        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_any_verified_user_can_create_a_workspace_and_becomes_its_owner()
    {
        $this->enableWorkspaces();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/workspaces', ['name' => 'Acme'])->assertRedirect();

        $workspace = Workspace::query()->sole();
        $this->assertSame('Acme', $workspace->name);
        $this->assertSame(WorkspaceRole::Owner, $user->membershipIn($workspace)?->role);
        $this->assertSame($workspace->id, $user->fresh()->current_workspace_id);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'workspace.created', 'actor_id' => $user->id]);
    }

    public function test_unverified_users_cannot_create_workspaces()
    {
        $this->enableWorkspaces();

        $this->actingAs(User::factory()->unverified()->create())
            ->post('/workspaces', ['name' => 'Acme'])
            ->assertRedirect('/email/verify');
    }

    public function test_renaming_needs_update_workspace()
    {
        $this->enableWorkspaces();
        $workspace = Workspace::factory()->create(['name' => 'Old']);

        $this->actingAs($this->memberOf(WorkspaceRole::Developer, $workspace))
            ->patchJson("/workspaces/{$workspace->id}", ['name' => 'New'])->assertForbidden();

        $this->actingAs($this->memberOf(WorkspaceRole::Maintainer, $workspace))
            ->patchJson("/workspaces/{$workspace->id}", ['name' => 'New'])->assertRedirect();

        $this->assertSame('New', $workspace->fresh()->name);
        $this->assertEquals(['from' => 'Old', 'to' => 'New'], AuditEvent::query()->where('event_type', 'workspace.renamed')->sole()->metadata); // MySQL json reorders keys
    }

    public function test_switching_workspace_and_falling_back_from_a_stale_selection()
    {
        $this->enableWorkspaces();
        $first = Workspace::factory()->create(['name' => 'First']);
        $second = Workspace::factory()->create(['name' => 'Second']);
        $user = $this->memberOf(WorkspaceRole::Viewer, $first);
        $this->memberOf(WorkspaceRole::Viewer, $second)->delete();
        app(WorkspaceService::class)->addMember($second, $user, WorkspaceRole::Developer);

        $this->actingAs($user)->put('/current-workspace', ['workspace_id' => $second->id])->assertRedirect('/dashboard');
        $this->assertSame($second->id, $user->fresh()->current_workspace_id);

        // Membership revoked behind the user's back: the next page falls back to what they can still see.
        $this->membershipOf($user, $second)->delete();
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('currentWorkspace.id', $first->id));
        $this->assertSame($first->id, $user->fresh()->current_workspace_id);
    }

    public function test_a_user_without_workspaces_still_gets_a_dashboard()
    {
        $this->enableWorkspaces();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('currentWorkspace', null)->where('workspaces', []));
    }

    public function test_the_sole_owner_cannot_delete_their_account()
    {
        $this->enableWorkspaces();
        $owner = $this->memberOf(WorkspaceRole::Owner);

        $this->actingAs($owner)
            ->from('/settings/profile')
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($owner->fresh());
        $this->assertAuthenticatedAs($owner);
    }
}
