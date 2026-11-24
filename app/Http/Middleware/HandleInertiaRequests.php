<?php

namespace App\Http\Middleware;

use App\Domain\Workspaces\WorkspaceImpersonation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Features;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Only to show ops-only links (e.g. Settings → GitHub App); the routes check it themselves.
                'isPlatformAdmin' => $request->user()?->is_platform_admin === true,
            ],
            // Flag names only (true/false), for hiding nav items. Routes enforce the flags themselves.
            'features' => array_map(fn (mixed $on): bool => $on === true, config()->array('features')),
            'workspaces' => fn () => $this->workspaces($request),
            'currentWorkspace' => fn () => $this->currentWorkspace($request),
            'impersonation' => fn () => $this->impersonation($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The signed-in user's workspaces, for the switcher. Null while the feature is off.
     *
     * @return array<int, array{id: string, name: string, roleLabel: string}>|null
     */
    private function workspaces(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User || ! Features::enabled('workspace_management')) {
            return null;
        }

        $memberships = $user->memberships()->with('workspace:id,name');
        $context = app(WorkspaceImpersonation::class)->context($request);

        if ($context !== null) {
            $memberships->forWorkspace($context['workspace_id']);
        }

        return $memberships->get()
            ->map(fn (WorkspaceMember $m) => [
                'id' => $m->workspace->id,
                'name' => $m->workspace->name,
                'roleLabel' => $m->role->label(),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * The selected workspace with the user's capabilities in it, computed
     * server-side from the one permission matrix. Never re-derive this in React.
     *
     * @return array{id: string, name: string, role: string, roleLabel: string, can: array<string, bool>}|null
     */
    private function currentWorkspace(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User || ! Features::enabled('workspace_management')) {
            return null;
        }

        $membership = $user->currentMembership();

        return $membership === null ? null : [
            'id' => $membership->workspace->id,
            'name' => $membership->workspace->name,
            'role' => $membership->role->value,
            'roleLabel' => $membership->role->label(),
            'can' => $membership->role->capabilities(),
        ];
    }

    /**
     * @return array{active: true, startedAt: string, owner: array{id: int, name: string}, member: array{id: int, name: string, email: string}, workspace: array{id: string, name: string}}|null
     */
    private function impersonation(Request $request): ?array
    {
        $context = app(WorkspaceImpersonation::class)->context($request);
        $member = $request->user();

        if ($context === null || ! $member instanceof User) {
            return null;
        }

        $owner = User::query()->find($context['impersonator_id']);
        $workspace = Workspace::query()->find($context['workspace_id']);

        if ($owner === null || $workspace === null) {
            return null;
        }

        return [
            'active' => true,
            'startedAt' => $context['started_at'],
            'owner' => ['id' => $owner->id, 'name' => $owner->name],
            'member' => ['id' => $member->id, 'name' => $member->name, 'email' => $member->email],
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
        ];
    }
}
