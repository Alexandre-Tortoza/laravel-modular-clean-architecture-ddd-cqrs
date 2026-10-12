<?php

namespace App\Modules\Security\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Api\SecurityAccounts;
use App\Modules\Security\Application\Commands\UpdatePassword;
use App\Modules\Security\Application\Commands\UpdatePasswordHandler;
use App\Modules\Security\Presentation\Http\Requests\PasswordUpdateRequest;
use App\Modules\Security\Presentation\Http\Requests\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request, SecurityAccounts $accounts): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'canManagePasskeys' => Features::canManagePasskeys(),
            'passkeys' => Features::canManagePasskeys()
                ? $accounts->passkeysFor($request->user()->id)
                : [],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $accounts->hasEnabledTwoFactorFor($request->user()->id);
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/Security', $props);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request, UpdatePasswordHandler $updatePassword): RedirectResponse
    {
        $updatePassword->handle(new UpdatePassword($request->user()->id, $request->password));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
