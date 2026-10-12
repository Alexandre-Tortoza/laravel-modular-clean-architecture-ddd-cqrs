<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Membership;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;

test('demo seed composes the existing user with a personal team', function () {
    $this->artisan('db:seed')->assertExitCode(0);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $team = Team::query()->findOrFail($user->current_team_id);
    $membership = Membership::query()
        ->where('user_id', $user->id)
        ->where('team_id', $team->id)
        ->firstOrFail();

    expect($user->name)->toBe('Test User')
        ->and($team->name)->toBe("Test User's Team")
        ->and($team->is_personal)->toBeTrue()
        ->and($membership->role)->toBe(TeamRole::Owner);

    $this->artisan('db:seed')->assertExitCode(0);

    expect(User::query()->where('email', 'test@example.com')->count())->toBe(1)
        ->and(Team::query()->where('is_personal', true)->count())->toBe(1);
});
