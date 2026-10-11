<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories;

use App\Models\User;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Illuminate\Support\Facades\DB;

class EloquentTeamManager implements ManagesTeams
{
    public function update(int $teamId, string $name): TeamData
    {
        return DB::transaction(function () use ($teamId, $name): TeamData {
            $team = Team::query()->lockForUpdate()->findOrFail($teamId);
            $team->update(['name' => $name]);

            return new TeamData($team->id, $team->name, $team->slug, $team->is_personal);
        });
    }

    public function switchCurrentTeam(int $userId, int $teamId): void
    {
        $user = User::query()->findOrFail($userId);
        $team = Team::query()->findOrFail($teamId);

        $user->switchTeam($team);
    }

    public function leave(int $userId, int $teamId): void
    {
        DB::transaction(function () use ($userId, $teamId): void {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $team = Team::query()->lockForUpdate()->findOrFail($teamId);
            $fallbackTeam = $user->isCurrentTeam($team) ? $user->fallbackTeam($team) : null;

            $team->memberships()->where('user_id', $user->id)->delete();

            if ($fallbackTeam) {
                $user->switchTeam($fallbackTeam);
            }
        });
    }

    public function delete(int $userId, int $teamId): void
    {
        DB::transaction(function () use ($userId, $teamId): void {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $team = Team::query()->lockForUpdate()->findOrFail($teamId);
            $fallbackTeam = $user->isCurrentTeam($team) ? $user->fallbackTeam($team) : null;

            User::query()
                ->where('current_team_id', $team->id)
                ->where('id', '!=', $user->id)
                ->each(fn (User $affectedUser) => $affectedUser->switchTeam($affectedUser->personalTeam()));

            $team->invitations()->delete();
            $team->memberships()->delete();
            $team->delete();

            if ($fallbackTeam) {
                $user->switchTeam($fallbackTeam);
            }
        });
    }

    public function updateMemberRole(int $teamId, int $userId, TeamRole $role): void
    {
        Team::query()
            ->findOrFail($teamId)
            ->memberships()
            ->where('user_id', $userId)
            ->firstOrFail()
            ->update(['role' => $role]);
    }

    public function removeMember(int $teamId, int $userId): void
    {
        DB::transaction(function () use ($teamId, $userId): void {
            $team = Team::query()->lockForUpdate()->findOrFail($teamId);
            $user = User::query()->lockForUpdate()->findOrFail($userId);

            $team->memberships()->where('user_id', $user->id)->delete();

            if ($user->isCurrentTeam($team)) {
                $user->switchTeam($user->personalTeam());
            }
        });
    }
}
