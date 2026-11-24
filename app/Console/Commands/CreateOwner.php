<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Domain\Workspaces\WorkspaceService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Signup is closed, so this is how the first account (or any extra owner) is made.
 * The password is always prompted, never an option, so it stays out of shell history.
 */
class CreateOwner extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    protected $signature = 'app:create-owner
        {--name= : Full name}
        {--email= : Email address (must not exist yet)}
        {--workspace= : Name of the workspace they will own}
        {--platform-admin : Also allow /ready and the Horizon dashboard}';

    protected $description = 'Create a verified user who owns a new workspace';

    public function handle(WorkspaceService $workspaces): int
    {
        $data = [
            'name' => $this->option('name') ?: text('Full name', required: true),
            'email' => Str::lower($this->option('email') ?: text('Email address', required: true)),
            'workspace' => $this->option('workspace') ?: text('Workspace name', default: 'My workspace', required: true),
            'password' => password('Password', required: true),
        ];
        $data['password_confirmation'] = password('Confirm password', required: true);

        $validator = Validator::make($data, [
            ...$this->profileRules(),
            'workspace' => ['required', 'string', 'max:100'],
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($data, $workspaces) {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'email_verified_at' => now(),
                'is_platform_admin' => (bool) $this->option('platform-admin'),
            ])->save();

            $workspaces->create($user, $data['workspace']);

            return $user;
        });

        $this->components->info("Created {$user->email}, owner of \"{$data['workspace']}\"".($user->is_platform_admin ? ' (platform admin)' : '').'.');

        return self::SUCCESS;
    }
}
