<?php

use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Profile\Application\Commands\DeleteProfile;
use App\Modules\Profile\Application\Commands\DeleteProfileHandler;
use App\Modules\Profile\Application\Commands\UpdateProfile;
use App\Modules\Profile\Application\Commands\UpdateProfileHandler;
use App\Modules\Profile\Application\Contracts\RunsProfileTransactions;
use App\Modules\Teams\Application\Api\AccountTeamLifecycle;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
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
    $sentInvitation = TeamInvitation::factory()->create(['team_id' => $personalTeam->id, 'invited_by' => $user->id]);
    $otherOwner = User::factory()->create();
    $sharedTeam = Team::factory()->create();
    $sharedTeam->memberships()->create(['user_id' => $otherOwner->id, 'role' => TeamRole::Owner]);
    $receivedInvitation = TeamInvitation::factory()->create(['team_id' => $sharedTeam->id, 'email' => $user->email, 'invited_by' => $otherOwner->id]);
    $accounts = Mockery::mock(ProfileAccounts::class);
    $accounts->shouldReceive('deleteOwnProfile')->once()->with($user->id)
        ->andThrow(new RuntimeException('Identity deletion failed.'));

    try {
        expect(fn () => (new DeleteProfileHandler(
            $accounts,
            app(AccountTeamLifecycle::class),
            app(RunsProfileTransactions::class),
        ))->handle(new DeleteProfile($user->id)))
            ->toThrow(RuntimeException::class, 'Identity deletion failed.');
    } finally {
        Mockery::close();
    }

    $this->assertModelExists($user);
    $this->assertDatabaseHas('teams', ['id' => $personalTeam->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('team_members', ['team_id' => $personalTeam->id, 'user_id' => $user->id]);
    $this->assertModelExists($sentInvitation);
    $this->assertModelExists($receivedInvitation);
});

test('account cleanup uses the locked current email and preserves received accepted invitations', function (): void {
    $user = UserWithPersonalTeam::create(['email' => 'original@example.com']);
    $command = new DeleteProfile($user->id);
    $otherOwner = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $otherOwner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $oldEmailInvitation = TeamInvitation::factory()->create(['team_id' => $team->id, 'email' => $user->email, 'invited_by' => $otherOwner->id]);
    $pending = TeamInvitation::factory()->create(['team_id' => $team->id, 'email' => 'CURRENT@example.com', 'invited_by' => $otherOwner->id]);
    $accepted = TeamInvitation::factory()->create(['team_id' => $team->id, 'email' => 'current@example.com', 'invited_by' => $otherOwner->id, 'accepted_at' => now()]);
    $sent = TeamInvitation::factory()->create(['team_id' => $team->id, 'invited_by' => $user->id, 'accepted_at' => now()]);
    app(UpdateProfileHandler::class)->handle(new UpdateProfile($user->id, $user->name, 'current@example.com'));

    app(DeleteProfileHandler::class)->handle($command);

    $this->assertModelMissing($user);
    $this->assertModelMissing($pending);
    $this->assertModelMissing($sent);
    $this->assertModelExists($accepted);
    $this->assertModelExists($oldEmailInvitation);
    $this->assertDatabaseHas('teams', ['id' => $team->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('team_members', ['team_id' => $team->id, 'user_id' => $otherOwner->id, 'role' => TeamRole::Owner->value]);
    $this->assertDatabaseMissing('team_members', ['team_id' => $team->id, 'user_id' => $user->id]);
});
