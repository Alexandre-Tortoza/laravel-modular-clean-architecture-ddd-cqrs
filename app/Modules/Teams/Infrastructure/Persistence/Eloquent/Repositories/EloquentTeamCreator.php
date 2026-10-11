<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories;

use App\Models\User;
use App\Modules\Teams\Application\Contracts\CreatesTeams;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Illuminate\Support\Facades\DB;

class EloquentTeamCreator implements CreatesTeams
{
    public function create(int $userId, string $name, bool $isPersonal): TeamData
    {
        return DB::transaction(function () use ($userId, $name, $isPersonal): TeamData {
            $user = User::query()->findOrFail($userId);
            $team = Team::query()->create([
                'name' => $name,
                'is_personal' => $isPersonal,
            ]);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner,
            ]);

            $user->switchTeam($team);

            return new TeamData(
                id: $team->id,
                name: $team->name,
                slug: $team->slug,
                isPersonal: $team->is_personal,
                role: TeamRole::Owner,
                isCurrent: true,
            );
        });
    }
}
