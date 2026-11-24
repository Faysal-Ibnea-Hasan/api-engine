<?php

namespace Tests\Feature\Workspaces;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\WorksWithWorkspaces;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase, WorksWithWorkspaces;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableWorkspaces();
        $this->withoutVite();
        Notification::fake();

        $this->workspace = Workspace::factory()->create(['name' => 'Acme']);
        $this->owner = $this->memberOf(WorkspaceRole::Owner, $this->workspace);
    }

    /** Invite through the real endpoint and return the token from the email. */
    private function invite(string $email, string $role = 'developer', ?User $as = null): string
    {
        $this->actingAs($as ?? $this->owner)
            ->post("/workspaces/{$this->workspace->id}/invitations", ['email' => $email, 'role' => $role])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function ($n, $channels, AnonymousNotifiable $to) use (&$token, $email) {
            $token = $n->token;

            return $to->routes['mail'] === Str::lower($email);
        });
        auth()->logout();

        return $token;
    }

    private function newInvitee(string $token, array $overrides = [])
    {
        return $this->post("/invitations/{$token}", $overrides + [
            'name' => 'New Person',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    public function test_an_invite_stores_only_the_token_hash_and_emails_the_link()
    {
        $token = $this->invite('New@Example.com', 'qa');

        $invitation = WorkspaceInvitation::query()->sole();
        $this->assertSame('new@example.com', $invitation->email);
        $this->assertSame(WorkspaceRole::QA, $invitation->role);
        $this->assertSame(WorkspaceInvitation::hashToken($token), $invitation->token_hash);
        $this->assertStringNotContainsString($token, WorkspaceInvitation::query()->get()->toJson());

        $mail = (new WorkspaceInvitationNotification($invitation, $token, 'Owner'))->toMail(new AnonymousNotifiable);
        $this->assertSame(route('invitations.show', $token), $mail->actionUrl);

        $audit = AuditEvent::query()->where('event_type', 'member.invited')->sole();
        $this->assertEquals(['email' => 'new@example.com', 'role' => 'qa'], $audit->metadata); // MySQL json reorders keys
        $this->assertStringNotContainsString($token, $audit->toJson());
    }

    public function test_a_new_person_accepts_sets_a_password_and_lands_in_the_workspace()
    {
        $token = $this->invite('new@example.com', 'qa');

        $this->get("/invitations/{$token}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('invitations/show')
            ->where('state', 'register')
            ->where('invitation.workspace', 'Acme')
            ->where('invitation.roleLabel', 'QA'));

        $this->newInvitee($token)->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'new@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(WorkspaceRole::QA, $user->membershipIn($this->workspace)?->role);
        $this->assertSame($this->workspace->id, $user->current_workspace_id);
        $this->assertNotNull(WorkspaceInvitation::query()->sole()->accepted_at);
        $this->assertStringNotContainsString('password', AuditEvent::query()->where('event_type', 'member.joined')->sole()->toJson());
    }

    public function test_a_link_works_only_once()
    {
        $token = $this->invite('new@example.com');
        $this->newInvitee($token)->assertRedirect('/dashboard');
        auth()->logout();

        $this->get("/invitations/{$token}")->assertNotFound()->assertInertia(fn ($page) => $page->where('state', 'invalid'));
        $this->newInvitee($token, ['name' => 'Second'])->assertRedirect(); // back to the link page, which shows "invalid"
        $this->assertSame(1, User::query()->where('email', 'new@example.com')->count());
    }

    public function test_expired_and_made_up_links_grant_nothing()
    {
        $token = Str::random(48);
        WorkspaceInvitation::factory()->expired()->for($this->workspace)->create([
            'email' => 'late@example.com',
            'token_hash' => WorkspaceInvitation::hashToken($token),
        ]);

        foreach ([$token, Str::random(48)] as $link) {
            $this->get("/invitations/{$link}")->assertNotFound();
            $this->newInvitee($link)->assertRedirect();
        }

        $this->assertDatabaseMissing('users', ['email' => 'late@example.com']);
        $this->assertGuest();
    }

    public function test_an_invite_for_an_existing_account_never_lets_a_guest_set_its_password()
    {
        $existing = User::factory()->create(['email' => 'dev@example.com']);
        $hash = $existing->password;
        $token = $this->invite('dev@example.com');

        $this->get("/invitations/{$token}")->assertRedirect('/login');
        $this->newInvitee($token, ['password' => 'attacker1', 'password_confirmation' => 'attacker1'])->assertRedirect('/login');

        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertFalse(Hash::check('attacker1', $existing->fresh()->password));
        $this->assertNull($existing->membershipIn($this->workspace));
        $this->assertGuest();

        // Once logged in as that account, the same link works.
        $this->actingAs($existing)->get("/invitations/{$token}")->assertOk()->assertInertia(fn ($page) => $page->where('state', 'accept'));
        $this->post("/invitations/{$token}")->assertRedirect("/workspaces/{$this->workspace->id}/members");
        $this->assertSame(WorkspaceRole::Developer, $existing->membershipIn($this->workspace)?->role);
    }

    public function test_a_signed_in_user_cannot_accept_someone_elses_invite()
    {
        $token = $this->invite('someone@example.com');
        $other = User::factory()->create();

        $this->actingAs($other)->get("/invitations/{$token}")->assertForbidden()
            ->assertInertia(fn ($page) => $page->where('state', 'wrong_account')->where('invitation', null));
        $this->post("/invitations/{$token}")->assertForbidden();

        $this->assertNull($other->membershipIn($this->workspace));
        $this->assertNull(WorkspaceInvitation::query()->sole()->accepted_at);
    }

    public function test_who_may_invite_whom()
    {
        $viewer = $this->memberOf(WorkspaceRole::Viewer, $this->workspace);
        $maintainer = $this->memberOf(WorkspaceRole::Maintainer, $this->workspace);

        $this->actingAs($viewer)->postJson("/workspaces/{$this->workspace->id}/invitations", ['email' => 'x@example.com', 'role' => 'viewer'])
            ->assertForbidden();
        $this->actingAs($maintainer)->postJson("/workspaces/{$this->workspace->id}/invitations", ['email' => 'x@example.com', 'role' => 'owner'])
            ->assertStatus(422)->assertJsonPath('error.code', 'workspace.role_not_assignable');
        $this->actingAs($maintainer)->postJson("/workspaces/{$this->workspace->id}/invitations", ['email' => Str::upper($viewer->email), 'role' => 'qa'])
            ->assertStatus(422)->assertJsonPath('error.code', 'workspace.already_member');

        $this->actingAs(User::factory()->create())->postJson("/workspaces/{$this->workspace->id}/invitations", ['email' => 'x@example.com', 'role' => 'viewer'])
            ->assertNotFound();

        $this->assertDatabaseCount('workspace_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_reinviting_replaces_the_old_link()
    {
        $old = $this->invite('new@example.com', 'viewer');
        $new = $this->invite('new@example.com', 'developer');

        $this->get("/invitations/{$old}")->assertNotFound();
        $this->get("/invitations/{$new}")->assertOk();
        $this->assertSame(WorkspaceRole::Developer, WorkspaceInvitation::query()->sole()->role);
    }

    public function test_revoking_kills_the_link_and_is_scoped_to_the_workspace()
    {
        $token = $this->invite('new@example.com');
        $invitation = WorkspaceInvitation::query()->sole();

        $other = Workspace::factory()->create();
        $this->actingAs($this->memberOf(WorkspaceRole::Owner, $other))
            ->deleteJson("/workspaces/{$other->id}/invitations/{$invitation->id}")->assertNotFound();

        $this->actingAs($this->owner)
            ->delete("/workspaces/{$this->workspace->id}/invitations/{$invitation->id}")->assertRedirect();

        auth()->logout();
        $this->get("/invitations/{$token}")->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['event_type' => 'invitation.revoked']);
    }

    public function test_an_invite_dies_when_its_inviter_loses_the_right_to_grant_it()
    {
        $maintainer = $this->memberOf(WorkspaceRole::Maintainer, $this->workspace);
        $token = $this->invite('new@example.com', 'developer', $maintainer);

        $this->membershipOf($maintainer, $this->workspace)->forceFill(['role' => WorkspaceRole::Viewer])->save();

        $this->newInvitee($token)->assertRedirect();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $this->assertGuest();
    }

    public function test_an_accepted_invitation_cannot_be_revoked()
    {
        $token = $this->invite('new@example.com');
        $this->newInvitee($token)->assertRedirect('/dashboard');
        $invitation = WorkspaceInvitation::query()->sole();

        $this->actingAs($this->owner)
            ->deleteJson("/workspaces/{$this->workspace->id}/invitations/{$invitation->id}")
            ->assertNotFound();

        $this->assertNotNull($invitation->fresh());
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'invitation.revoked']);
    }
}
