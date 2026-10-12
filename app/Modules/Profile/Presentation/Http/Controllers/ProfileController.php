<?php

namespace App\Modules\Profile\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Profile\Application\Commands\DeleteProfile;
use App\Modules\Profile\Application\Commands\DeleteProfileHandler;
use App\Modules\Profile\Application\Commands\UpdateProfile;
use App\Modules\Profile\Application\Commands\UpdateProfileHandler;
use App\Modules\Profile\Domain\Exceptions\ProfileDeletionRejected;
use App\Modules\Profile\Presentation\Http\Requests\ProfileDeleteRequest;
use App\Modules\Profile\Presentation\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
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

        try {
            $deleteProfile->handle(new DeleteProfile($user->id));
        } catch (ProfileDeletionRejected $exception) {
            throw ValidationException::withMessages(['account' => $exception->getMessage()]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
