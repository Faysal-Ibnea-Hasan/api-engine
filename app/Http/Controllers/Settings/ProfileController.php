<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Workspaces\MembershipError;
use App\Domain\Workspaces\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user) {
            // Lock every owner row of the user's workspaces so two co-owners deleting
            // at the same moment can't both pass the check and leave no owner.
            WorkspaceMember::query()
                ->whereIn('workspace_id', $user->memberships()->where('role', WorkspaceRole::Owner)->select('workspace_id'))
                ->where('role', WorkspaceRole::Owner)
                ->lockForUpdate()
                ->get();

            if ($user->isSoleOwnerOfAnyWorkspace()) {
                throw MembershipError::soleOwnerCannotDeleteAccount();
            }

            // Log out before deleting: logout saves the remember token, which would re-insert a deleted user.
            Auth::logout();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
