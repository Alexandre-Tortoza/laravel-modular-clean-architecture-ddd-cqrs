<?php

use App\Models\User;
use App\Modules\Profile\Application\Commands\UpdateProfile;
use App\Modules\Profile\Application\Commands\UpdateProfileHandler;

test('profile handler invalidates email verification only when the email changes', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    app(UpdateProfileHandler::class)->handle(new UpdateProfile($user->id, 'Updated Name', $user->email));

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    app(UpdateProfileHandler::class)->handle(new UpdateProfile($user->id, 'Updated Name', 'updated@example.com'));

    expect($user->fresh()->email)->toBe('updated@example.com')
        ->and($user->fresh()->email_verified_at)->toBeNull();
});
