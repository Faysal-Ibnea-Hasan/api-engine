<?php

namespace App\Http\Middleware;

use App\Domain\Workspaces\WorkspaceImpersonation;
use App\Domain\Workspaces\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceWorkspaceImpersonation
{
    public function __construct(private readonly WorkspaceImpersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->impersonation->context($request);

        if ($context === null) {
            return $next($request);
        }

        $target = $request->user();
        $owner = User::query()->find($context['impersonator_id']);
        $workspace = Workspace::query()->find($context['workspace_id']);
        $ownerMembership = $owner?->membershipIn($context['workspace_id']);
        $targetMembership = $target instanceof User
            ? $target->membershipIn($context['workspace_id'])
            : null;

        if (! $target instanceof User
            || $target->id !== $context['target_user_id']
            || $workspace === null
            || $ownerMembership?->role !== WorkspaceRole::Owner
            || $targetMembership === null) {
            $restored = $this->impersonation->stop($request, 'authorization_revoked');

            return $restored === null
                ? redirect()->route('login')
                : redirect()->route('dashboard');
        }

        // Select the impersonated workspace for this request without changing the
        // member's persisted workspace preference in their own sessions.
        $target->setAttribute('current_workspace_id', $workspace->id);

        if ($this->isAccountOrWorkspaceSelectionRoute($request)) {
            abort(403, 'This action is unavailable while using Login as.');
        }

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            $parameterWorkspaceId = $this->workspaceId($parameter);

            if ($parameterWorkspaceId !== null && $parameterWorkspaceId !== $workspace->id) {
                abort(404);
            }
        }

        return $next($request);
    }

    private function isAccountOrWorkspaceSelectionRoute(Request $request): bool
    {
        if ($request->routeIs('workspace-impersonation.destroy')) {
            return false;
        }

        return $request->routeIs(
            'workspaces.index',
            'workspaces.store',
            'current-workspace.update',
            'profile.*',
            'security.*',
            'user-password.*',
            'passkey.*',
            'two-factor.*',
            'password.confirm*',
            'verification.*',
            'logout',
            'invitations.*',
            'ready',
        ) || $request->is('settings', 'settings/*', 'user/*');
    }

    private function workspaceId(mixed $parameter): ?string
    {
        if ($parameter instanceof Workspace) {
            return (string) $parameter->getKey();
        }

        if (! $parameter instanceof Model) {
            return null;
        }

        $workspaceId = $parameter->getAttribute('workspace_id');

        return is_string($workspaceId) ? $workspaceId : null;
    }
}
