<?php

use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Profile\Application\Commands\DeleteProfile;
use App\Modules\Profile\Application\Commands\DeleteProfileHandler;
use App\Modules\Profile\Application\Commands\UpdateProfile;
use App\Modules\Profile\Application\Commands\UpdateProfileHandler;
use App\Modules\Profile\Application\Contracts\RunsProfileTransactions;
use App\Modules\Teams\Application\Api\AccountTeamLifecycle;
use Tests\Support\UserWithPersonalTeam;

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

test('account resource cleanup rolls back when identity deletion fails', function (): void {
    $user = UserWithPersonalTeam::create();
    $personalTeam = UserWithPersonalTeam::personalTeam($user);
    $accounts = Mockery::mock(ProfileAccounts::class);
    $accounts->shouldReceive('deleteOwnProfile')->once()->with($user->id)
        ->andThrow(new RuntimeException('Identity deletion failed.'));

    try {
        expect(fn () => (new DeleteProfileHandler(
            $accounts,
            app(AccountTeamLifecycle::class),
            app(RunsProfileTransactions::class),
        ))->handle(new DeleteProfile($user->id, $user->email)))
            ->toThrow(RuntimeException::class, 'Identity deletion failed.');
    } finally {
        Mockery::close();
    }

    $this->assertModelExists($user);
    $this->assertDatabaseHas('teams', ['id' => $personalTeam->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('team_members', ['team_id' => $personalTeam->id, 'user_id' => $user->id]);
});
