<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories;

use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Teams\Application\Contracts\CreatesTeams;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\CurrentTeamSelection;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;

class EloquentTeamCreator implements CreatesTeams
{
    public function __construct(private UserDirectory $users, private CurrentTeamSelection $selection) {}

    public function create(int $userId, string $name, bool $isPersonal): TeamData
    {
        $team = Team::query()->create(['name' => $name, 'is_personal' => $isPersonal]);
        $user = $this->users->lockMany([$userId])[$userId];
        $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);
        $this->selection->select($user->id, $team->id);

        return new TeamData($team->id, $team->name, $team->slug, $team->is_personal,
            TeamRole::Owner, true);
    }
}
