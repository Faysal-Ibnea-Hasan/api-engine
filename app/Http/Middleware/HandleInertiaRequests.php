<?php

namespace App\Http\Middleware;

use App\Models\User;
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
            ],
            // Flag names only (true/false), for hiding nav items. Routes enforce the flags themselves.
            'features' => array_map(fn (mixed $on): bool => $on === true, config()->array('features')),
            'workspaces' => fn () => $this->workspaces($request),
            'currentWorkspace' => fn () => $this->currentWorkspace($request),
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

        return $user->memberships()->with('workspace:id,name')->get()
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
}
