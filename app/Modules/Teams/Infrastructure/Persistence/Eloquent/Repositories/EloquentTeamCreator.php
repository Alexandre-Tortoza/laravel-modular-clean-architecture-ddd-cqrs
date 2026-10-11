<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories;

use App\Models\User;
use App\Modules\Teams\Application\Contracts\CreatesTeams;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;

class EloquentTeamCreator implements CreatesTeams
{
    public function create(int $userId, string $name, bool $isPersonal): TeamData
    {
        $team = Team::query()->create([
            'name' => $name,
            'is_personal' => $isPersonal,
        ]);

        $user = User::query()->lockForUpdate()->findOrFail($userId);

        $team->memberships()->create([
            'user_id' => $user->id,
            'role' => TeamRole::Owner,
        ]);

        $user->update(['current_team_id' => $team->id]);

        return new TeamData(
            id: $team->id,
            name: $team->name,
            slug: $team->slug,
            isPersonal: $team->is_personal,
            role: TeamRole::Owner,
            isCurrent: true,
        );
    }
}
