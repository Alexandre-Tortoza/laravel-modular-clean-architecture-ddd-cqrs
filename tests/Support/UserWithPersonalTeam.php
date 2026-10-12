<?php

namespace Tests\Support;

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Commands\CreateTeam;
use App\Modules\Teams\Application\Commands\CreateTeamHandler;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;

class UserWithPersonalTeam
{
    /** @param array<string, mixed> $attributes */
    public static function create(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        app(CreateTeamHandler::class)->handle(new CreateTeam(
            userId: $user->id,
            name: $user->name."'s Team",
            isPersonal: true,
        ));

        return $user->refresh();
    }

    public static function personalTeam(User $user): Team
    {
        foreach (app(TeamContext::class)->forUser($user->id, includeCurrent: true) as $team) {
            if ($team->isPersonal) {
                return Team::query()->findOrFail($team->id);
            }
        }

        throw new \LogicException('The user has no personal team.');
    }
}
