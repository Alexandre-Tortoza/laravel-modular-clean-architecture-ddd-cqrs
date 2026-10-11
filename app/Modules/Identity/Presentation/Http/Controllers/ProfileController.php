<?php

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Commands\DeleteProfile;
use App\Modules\Identity\Application\Commands\DeleteProfileHandler;
use App\Modules\Identity\Application\Commands\UpdateProfile;
use App\Modules\Identity\Application\Commands\UpdateProfileHandler;
use App\Modules\Identity\Presentation\Http\Requests\ProfileDeleteRequest;
use App\Modules\Identity\Presentation\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, UpdateProfileHandler $updateProfile): RedirectResponse
    {
        $updateProfile->handle(new UpdateProfile($request->user()->id, $request->validated('name'), $request->validated('email')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteProfileHandler $deleteProfile): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $deleteProfile->handle(new DeleteProfile($user->id));

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
