<?php

namespace Tests\Feature\Workspaces;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class MembershipRulesTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        $this->workspace = Workspace::factory()->create();
    }

    private function url(string $suffix = ''): string
    {
        return "/workspaces/{$this->workspace->id}/members{$suffix}";
    }

    public function test_every_member_can_see_the_member_list_but_only_managers_see_invitations()
    {
        $this->actingAs($this->memberOf(WorkspaceRole::Viewer, $this->workspace))
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('workspaces/members')
                ->where('can.manageMembers', false)
                ->has('invitations', 0)
                ->has('assignableRoles', 0));
    }

    public function test_roles_without_manage_members_get_403_not_404()
    {
        $target = $this->membershipOf($this->memberOf(WorkspaceRole::Viewer, $this->workspace), $this->workspace);

        foreach ([WorkspaceRole::Developer, WorkspaceRole::QA, WorkspaceRole::Viewer] as $role) {
            $this->actingAs($this->memberOf($role, $this->workspace));

            $this->patchJson($this->url("/{$target->id}"), ['role' => 'developer'])->assertForbidden();
            $this->deleteJson($this->url("/{$target->id}"))->assertForbidden();
        }

        $this->assertSame(WorkspaceRole::Viewer, $target->fresh()->role);
    }

    public function test_a_maintainer_manages_non_owners_but_cannot_touch_or_create_owners()
    {
        $owner = $this->membershipOf($this->memberOf(WorkspaceRole::Owner, $this->workspace), $this->workspace);
        $dev = $this->membershipOf($this->memberOf(WorkspaceRole::Developer, $this->workspace), $this->workspace);
        $this->actingAs($this->memberOf(WorkspaceRole::Maintainer, $this->workspace));

        $this->patchJson($this->url("/{$dev->id}"), ['role' => 'qa'])->assertRedirect();
        $this->assertSame(WorkspaceRole::QA, $dev->fresh()->role);

        $this->patchJson($this->url("/{$dev->id}"), ['role' => 'owner'])
            ->assertStatus(422)->assertJsonPath('error.code', 'workspace.role_not_assignable');
        $this->patchJson($this->url("/{$owner->id}"), ['role' => 'viewer'])
            ->assertStatus(422)->assertJsonPath('error.code', 'workspace.owner_protected');
        $this->deleteJson($this->url("/{$owner->id}"))
            ->assertStatus(422)->assertJsonPath('error.code', 'workspace.owner_protected');

        $this->assertSame(WorkspaceRole::Owner, $owner->fresh()->role);
    }

    public function test_the_last_owner_cannot_be_demoted_or_leave()
    {
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $membership = $this->membershipOf($owner, $this->workspace);
        $this->actingAs($owner);

        $this->patchJson($this->url("/{$membership->id}"), ['role' => 'maintainer'])
            ->assertStatus(422)->assertJsonPath('error.code', 'workspace.last_owner');
        $this->deleteJson($this->url("/{$membership->id}"))
            ->assertStatus(422)->assertJsonPath('error.code', 'workspace.last_owner');

        $this->assertSame(WorkspaceRole::Owner, $membership->fresh()->role);
    }

    public function test_with_two_owners_one_can_step_down()
    {
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $membership = $this->membershipOf($owner, $this->workspace);

        $this->actingAs($owner)->patchJson($this->url("/{$membership->id}"), ['role' => 'developer'])->assertRedirect();

        $this->assertSame(WorkspaceRole::Developer, $membership->fresh()->role);
    }

    public function test_a_removed_member_loses_access_on_the_very_next_request()
    {
        $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $removed = $this->memberOf(WorkspaceRole::Developer, $this->workspace);
        $removed->switchWorkspace($this->workspace->id);
        $membership = $this->membershipOf($removed, $this->workspace);

        $this->actingAs($this->memberOf(WorkspaceRole::Maintainer, $this->workspace))
            ->deleteJson($this->url("/{$membership->id}"))->assertRedirect();

        $this->assertNull($removed->fresh()->current_workspace_id);
        $this->actingAs($removed->fresh())->getJson($this->url())->assertNotFound();
    }

    public function test_any_member_can_leave()
    {
        $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $viewer = $this->memberOf(WorkspaceRole::Viewer, $this->workspace);

        $this->actingAs($viewer)
            ->delete($this->url('/'.$this->membershipOf($viewer, $this->workspace)->id))
            ->assertRedirect('/workspaces');

        $this->assertNull($viewer->membershipIn($this->workspace));
    }

    public function test_role_changes_and_removals_are_audited_without_secrets()
    {
        $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $dev = $this->membershipOf($this->memberOf(WorkspaceRole::Developer, $this->workspace), $this->workspace);
        $actor = $this->memberOf(WorkspaceRole::Maintainer, $this->workspace);
        $this->actingAs($actor);

        $this->patchJson($this->url("/{$dev->id}"), ['role' => 'qa', 'password' => 'hunter2'])->assertRedirect();
        $this->deleteJson($this->url("/{$dev->id}"))->assertRedirect();

        $events = AuditEvent::query()->forWorkspace($this->workspace)->orderBy('id')->get();
        $this->assertSame(['member.role_changed', 'member.removed'], $events->pluck('event_type')->all());
        $this->assertEquals(['from' => 'developer', 'to' => 'qa'], $events[0]->metadata); // MySQL json reorders keys
        $this->assertSame($actor->id, $events[0]->actor_id);
        $this->assertSame((string) $dev->user_id, $events[1]->target_id);
        $this->assertNotNull($events[0]->correlation_id);
        $this->assertStringNotContainsString('hunter2', $events->toJson());
    }

    public function test_invalid_roles_are_rejected()
    {
        $dev = $this->membershipOf($this->memberOf(WorkspaceRole::Developer, $this->workspace), $this->workspace);

        $this->actingAs($this->memberOf(WorkspaceRole::Owner, $this->workspace))
            ->patchJson($this->url("/{$dev->id}"), ['role' => 'superadmin'])
            ->assertJsonValidationErrors('role');
    }
}
