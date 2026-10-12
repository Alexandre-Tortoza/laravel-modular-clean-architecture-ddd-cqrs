<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;

test('membership snapshot has no personal fallback for an existing user without a personal team', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    $membership = app(ManagesTeams::class)->membershipForUpdate($team->id, $user->id);

    expect($membership->personalTeamId)->toBeNull()
        ->and($membership->role)->toBe(TeamRole::Member);
});
