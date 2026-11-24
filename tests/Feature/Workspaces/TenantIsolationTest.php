<?php

namespace Tests\Feature\Workspaces;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

/** RG-01: Workspace A cannot reach Workspace B, even with forged IDs. */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private Workspace $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();

        $this->theirs = Workspace::factory()->create();
        $this->memberOf(WorkspaceRole::Owner, $this->theirs);
        $this->actingAs($this->memberOf(WorkspaceRole::Owner));
    }

    public function test_another_tenants_workspace_looks_exactly_like_a_missing_one()
    {
        config(['app.debug' => false]); // compare what production returns
        $missing = (string) Str::uuid();

        foreach ([
            fn (string $id) => $this->getJson("/workspaces/{$id}/members"),
            fn (string $id) => $this->patchJson("/workspaces/{$id}", ['name' => 'Hijacked']),
            fn (string $id) => $this->putJson('/current-workspace', ['workspace_id' => $id]),
        ] as $request) {
            $foreign = $request($this->theirs->id)->assertNotFound();
            $absent = $request($missing)->assertNotFound();

            $this->assertSame($absent->json(), $foreign->json());
            $this->assertStringNotContainsString('App\\', $foreign->getContent());
        }

        $this->assertSame($this->theirs->name, $this->theirs->fresh()->name);
    }

    public function test_another_tenants_member_ids_are_not_found_through_my_workspace()
    {
        $mine = $this->membershipOf(auth()->user(), Workspace::query()->whereKeyNot($this->theirs->id)->firstOrFail());
        $victim = $this->theirs->members()->firstOrFail();

        // My workspace in the URL, their member's ID: scoped binding must not find it.
        $this->patchJson("/workspaces/{$mine->workspace_id}/members/{$victim->id}", ['role' => 'viewer'])->assertNotFound();
        $this->deleteJson("/workspaces/{$mine->workspace_id}/members/{$victim->id}")->assertNotFound();

        // Their workspace in the URL: not a member, so not found.
        $this->patchJson("/workspaces/{$this->theirs->id}/members/{$victim->id}", ['role' => 'viewer'])->assertNotFound();
        $this->deleteJson("/workspaces/{$this->theirs->id}/members/{$victim->id}")->assertNotFound();

        $this->assertSame(WorkspaceRole::Owner, $victim->fresh()->role);
    }

    public function test_the_workspace_list_and_shared_props_show_only_my_workspaces()
    {
        $this->get('/workspaces')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('myWorkspaces', 1)
                ->where('myWorkspaces.0.role', 'owner')
                ->has('workspaces', 1)
                ->has('auth')
                ->has('currentWorkspace')
                ->where('currentWorkspace.role', 'owner')
                ->where('workspaces', fn ($list) => collect($list)->doesntContain('id', $this->theirs->id)));
    }

    public function test_an_invalid_body_does_not_reveal_that_a_foreign_workspace_exists()
    {
        config(['app.debug' => false]);
        $missing = (string) Str::uuid();
        $victim = $this->theirs->members()->firstOrFail();

        foreach ([
            fn (string $id) => $this->patchJson("/workspaces/{$id}", ['name' => '']),
            fn (string $id) => $this->postJson("/workspaces/{$id}/invitations", ['email' => 'not-an-email', 'role' => 'nope']),
        ] as $request) {
            $this->assertSame($request($missing)->assertNotFound()->json(), $request($this->theirs->id)->assertNotFound()->json());
        }

        $this->patchJson("/workspaces/{$this->theirs->id}/members/{$victim->id}", ['role' => 'nope'])->assertNotFound();
    }
}
