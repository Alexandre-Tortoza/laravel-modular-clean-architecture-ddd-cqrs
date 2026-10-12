<?php

use App\Modules\Teams\Presentation\Http\Controllers\TeamController;
use App\Modules\Teams\Presentation\Http\Controllers\TeamInvitationController;
use App\Modules\Teams\Presentation\Http\Controllers\TeamMemberController;
use App\Modules\Teams\Presentation\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::middleware(['auth', 'verified'])->group(function (): void {
        Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
        Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
    });

    Route::middleware(['auth', 'verified'])->group(function (): void {
        Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
        Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

        Route::middleware(EnsureTeamMembership::class)->group(function (): void {
            Route::get('settings/teams/{team}', [TeamController::class, 'edit'])->name('teams.edit');
            Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
            Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
            Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
            Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');

            Route::patch('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
            Route::delete('settings/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');

            Route::post('settings/teams/{team}/invitations', [TeamInvitationController::class, 'store'])->name('teams.invitations.store');
            Route::delete('settings/teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
        });
    });
});
