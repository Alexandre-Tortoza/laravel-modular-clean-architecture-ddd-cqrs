<?php

use App\Modules\Security\Presentation\Http\Controllers\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)->name('security.edit');
    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')->name('user-password.update');
});

Route::middleware('web')->get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
