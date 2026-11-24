<?php

namespace Tests\Feature\Workspaces;

use App\Domain\Workspaces\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_owner_and_their_workspace()
    {
        $this->artisan('app:create-owner', ['--name' => 'Ada', '--email' => 'Ada@Example.com', '--workspace' => 'Ops', '--platform-admin' => true])
            ->expectsQuestion('Password', 'password')
            ->expectsQuestion('Confirm password', 'password')
            ->assertSuccessful();

        $user = User::query()->where('email', 'ada@example.com')->sole();
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->is_platform_admin);
        $this->assertSame(WorkspaceRole::Owner, $user->membershipIn(Workspace::query()->sole())?->role);
    }

    public function test_it_refuses_an_existing_email_or_mismatched_passwords()
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('app:create-owner', ['--name' => 'X', '--email' => 'taken@example.com', '--workspace' => 'W'])
            ->expectsQuestion('Password', 'password')
            ->expectsQuestion('Confirm password', 'password')
            ->assertFailed();

        $this->artisan('app:create-owner', ['--name' => 'X', '--email' => 'new@example.com', '--workspace' => 'W'])
            ->expectsQuestion('Password', 'password')
            ->expectsQuestion('Confirm password', 'different')
            ->assertFailed();

        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }
}
