<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories;

use App\Modules\Teams\Application\Contracts\CreatesTeams;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;

class EloquentTeamCreator implements CreatesTeams
{
    public function create(int $userId, string $name, bool $isPersonal): TeamData
    {
        $team = Team::query()->create(['name' => $name, 'is_personal' => $isPersonal]);
        $team->memberships()->create(['user_id' => $userId, 'role' => TeamRole::Owner]);

        return new TeamData($team->id, $team->name, $team->slug, $team->is_personal,
            TeamRole::Owner);
    }
}
