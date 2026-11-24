<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Workspaces\InvitationError;
use App\Domain\Workspaces\InvitationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\AcceptInvitationRequest;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public invite link. Guests whose email already has an account are sent to
 * log in first: an invitation must never let someone set an existing user's password.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token, InvitationService $invitations): Response
    {
        $invitation = $invitations->findPending($token);

        if ($invitation === null) {
            return $this->page($request, 'invalid', status: 404);
        }

        $user = $request->user();

        if ($user === null) {
            return $this->accountExists($invitation)
                ? $this->toLogin()
                : $this->page($request, 'register', $invitation);
        }

        return $invitations->isFor($invitation, $user)
            ? $this->page($request, 'accept', $invitation)
            : $this->page($request, 'wrong_account', status: 403);
    }

    public function store(Request $request, string $token, InvitationService $invitations): RedirectResponse
    {
        $invitation = $invitations->findPending($token) ?? throw InvitationError::invalid();
        $user = $request->user();

        if ($user !== null) {
            abort_unless($invitations->isFor($invitation, $user), 403);
            $member = $invitations->accept($invitation, $user);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('You joined :workspace.', ['workspace' => $invitation->workspace->name])]);

            return to_route('workspaces.members.index', $member->workspace_id);
        }

        if ($this->accountExists($invitation)) {
            return $this->toLogin();
        }

        $data = app(AcceptInvitationRequest::class)->validated();
        $user = $invitations->acceptAsNewUser($invitation, $data['name'], $data['password']);

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }

    private function accountExists(WorkspaceInvitation $invitation): bool
    {
        return User::query()->where('email', $invitation->email)->exists();
    }

    private function toLogin(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Log in to accept this invitation.')]);

        return redirect()->guest(route('login'));
    }

    private function page(Request $request, string $state, ?WorkspaceInvitation $invitation = null, int $status = 200): Response
    {
        return Inertia::render('invitations/show', [
            'state' => $state,
            'token' => $invitation === null ? null : $request->route('token'),
            'invitation' => $invitation === null ? null : [
                'workspace' => $invitation->workspace->name,
                'email' => $invitation->email,
                'roleLabel' => $invitation->role->label(),
            ],
            'passwordRules' => $state === 'register' ? Password::defaults()->toPasswordRulesString() : null,
        ])->toResponse($request)->setStatusCode($status);
    }
}
