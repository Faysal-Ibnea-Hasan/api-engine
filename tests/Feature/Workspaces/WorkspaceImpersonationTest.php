<?php

namespace Tests\Feature\Workspaces;

use App\Domain\Workspaces\WorkspaceImpersonation;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class WorkspaceImpersonationTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        $this->workspace = Workspace::factory()->create();
    }

    private function startUrl(WorkspaceMember $member, ?Workspace $workspace = null): string
    {
        return '/workspaces/'.($workspace ?? $this->workspace)->id.'/members/'.$member->id.'/login-as';
    }

    public function test_an_owner_can_log_in_as_a_member_and_return_to_the_owner_account(): void
    {
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $member = $this->membershipOf($this->memberOf(WorkspaceRole::Viewer, $this->workspace), $this->workspace);

        $this->actingAs($owner)
            ->post($this->startUrl($member))
            ->assertRedirect('/dashboard')
            ->assertSessionHas(WorkspaceImpersonation::SESSION_KEY.'.impersonator_id', $owner->id)
            ->assertSessionHas(WorkspaceImpersonation::SESSION_KEY.'.target_user_id', $member->user_id)
            ->assertSessionHas(WorkspaceImpersonation::SESSION_KEY.'.workspace_id', $this->workspace->id);

        $this->assertAuthenticatedAs($member->user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.id', $member->user_id)
                ->where('currentWorkspace.id', $this->workspace->id)
                ->where('currentWorkspace.role', 'viewer')
                ->has('workspaces', 1)
                ->where('workspaces.0.id', $this->workspace->id)
                ->where('impersonation.active', true)
                ->where('impersonation.owner.id', $owner->id)
                ->where('impersonation.member.id', $member->user_id));

        $this->delete('/workspace-impersonation')
            ->assertRedirect('/dashboard')
            ->assertSessionMissing(WorkspaceImpersonation::SESSION_KEY);

        $this->assertAuthenticatedAs($owner);
        $this->assertSame($this->workspace->id, $owner->fresh()->current_workspace_id);

        $events = AuditEvent::query()->orderBy('id')->get();
        $this->assertSame(
            ['member.impersonation_started', 'member.impersonation_ended'],
            $events->pluck('event_type')->all(),
        );
        $this->assertSame([$owner->id, $owner->id], $events->pluck('actor_id')->all());
        $this->assertSame((string) $member->user_id, $events->first()->target_id);
    }

    public function test_login_as_does_not_change_the_members_own_workspace_preference(): void
    {
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $member = $this->memberOf(WorkspaceRole::Developer, $this->workspace);
        $other = Workspace::factory()->create();
        WorkspaceMember::factory()->for($other)->for($member)->role(WorkspaceRole::Developer)->create();
        $member->switchWorkspace($other->id);

        $this->actingAs($owner)->post($this->startUrl($this->membershipOf($member, $this->workspace)))->assertRedirect();

        $this->get('/dashboard')->assertOk();
        $this->assertSame($other->id, $member->fresh()->current_workspace_id);
    }

    public function test_only_an_owner_can_start_login_as_and_the_target_must_be_in_the_workspace(): void
    {
        $target = $this->membershipOf($this->memberOf(WorkspaceRole::Viewer, $this->workspace), $this->workspace);

        $this->actingAs($this->memberOf(WorkspaceRole::Maintainer, $this->workspace))
            ->post($this->startUrl($target))
            ->assertForbidden();

        $owner = $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $this->actingAs($owner)
            ->post($this->startUrl($this->membershipOf($owner, $this->workspace)))
            ->assertStatus(422);

        $other = Workspace::factory()->create();
        $outsider = WorkspaceMember::factory()->for($other)->role(WorkspaceRole::Viewer)->create();

        $this->actingAs($owner)
            ->post($this->startUrl($outsider))
            ->assertNotFound();
    }

    public function test_impersonation_is_limited_to_the_selected_workspace_and_member_permissions(): void
    {
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $viewer = $this->memberOf(WorkspaceRole::Viewer, $this->workspace);
        $viewerMembership = $this->membershipOf($viewer, $this->workspace);
        $otherMember = $this->membershipOf($this->memberOf(WorkspaceRole::Developer, $this->workspace), $this->workspace);

        $other = Workspace::factory()->create();
        WorkspaceMember::factory()->for($other)->for($viewer)->role(WorkspaceRole::Owner)->create();

        $this->actingAs($owner)->post($this->startUrl($viewerMembership))->assertRedirect();

        $this->patchJson("/workspaces/{$this->workspace->id}/members/{$otherMember->id}", ['role' => 'qa'])
            ->assertForbidden();
        $this->get("/workspaces/{$other->id}/members")->assertNotFound();
        $this->get('/workspaces')->assertForbidden();
        $this->get('/settings/profile')->assertForbidden();
        $this->post('/logout')->assertForbidden();

        $this->assertAuthenticatedAs($viewer);
    }

    public function test_authorization_revocation_ends_the_impersonation_on_the_next_request(): void
    {
        $owner = $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $this->memberOf(WorkspaceRole::Owner, $this->workspace);
        $target = $this->membershipOf($this->memberOf(WorkspaceRole::Viewer, $this->workspace), $this->workspace);

        $this->actingAs($owner)->post($this->startUrl($target))->assertRedirect();
        $this->membershipOf($owner, $this->workspace)
            ->forceFill(['role' => WorkspaceRole::Maintainer])
            ->save();

        $this->get('/dashboard')
            ->assertRedirect('/dashboard')
            ->assertSessionMissing(WorkspaceImpersonation::SESSION_KEY);

        $this->assertAuthenticatedAs($owner);
        $this->assertSame(
            'authorization_revoked',
            AuditEvent::query()->where('event_type', 'member.impersonation_ended')->sole()->metadata['reason'],
        );
    }
}
