<?php

use App\Modules\Identity\Application\Commands\UpdateProfile;
use App\Modules\Identity\Application\Commands\UpdateProfileHandler;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;

test('profile handler invalidates email verification only when the email changes', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    app(UpdateProfileHandler::class)->handle(new UpdateProfile($user->id, 'Updated Name', $user->email));

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    app(UpdateProfileHandler::class)->handle(new UpdateProfile($user->id, 'Updated Name', 'updated@example.com'));

    expect($user->fresh()->email)->toBe('updated@example.com')
        ->and($user->fresh()->email_verified_at)->toBeNull();
});

test('profile handler clears verification when reverting a verified email change', function () {
    $user = User::factory()->create([
        'email' => 'changed@example.com',
        'email_verified_at' => now(),
    ]);

    app(UpdateProfileHandler::class)->handle(new UpdateProfile($user->id, 'Updated Name', 'original@example.com'));

    expect($user->fresh()->email)->toBe('original@example.com')
        ->and($user->fresh()->email_verified_at)->toBeNull();
});
