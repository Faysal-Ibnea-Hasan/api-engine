<?php

use App\Http\Controllers\Workspaces\CurrentWorkspaceController;
use App\Http\Controllers\Workspaces\InvitationController;
use App\Http\Controllers\Workspaces\WorkspaceController;
use App\Http\Controllers\Workspaces\WorkspaceImpersonationController;
use App\Http\Controllers\Workspaces\WorkspaceInvitationController;
use App\Http\Controllers\Workspaces\WorkspaceMemberController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:workspace_management'])->group(function () {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::patch('workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
    Route::put('current-workspace', CurrentWorkspaceController::class)->name('current-workspace.update');

    Route::scopeBindings()->group(function () {
        Route::get('workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index'])->name('workspaces.members.index');
        Route::post('workspaces/{workspace}/members/{member}/login-as', [WorkspaceImpersonationController::class, 'store'])->name('workspace-impersonation.store');
        Route::patch('workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'update'])->name('workspaces.members.update');
        Route::delete('workspaces/{workspace}/members/{member}', [WorkspaceMemberController::class, 'destroy'])->name('workspaces.members.destroy');
        Route::post('workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'store'])->name('workspaces.invitations.store');
        Route::delete('workspaces/{workspace}/invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy'])->name('workspaces.invitations.destroy');
    });

    Route::delete('workspace-impersonation', [WorkspaceImpersonationController::class, 'destroy'])->name('workspace-impersonation.destroy');
});

// Public invite link: works for guests (new account) and signed-in invitees.
Route::middleware(['feature:workspace_management', 'throttle:invitations'])->group(function () {
    Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'store'])->name('invitations.accept');
});
