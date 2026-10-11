<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories;

use App\Models\User;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Application\Data\TeamDeletionData;
use App\Modules\Teams\Application\Data\TeamMembershipData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;

class EloquentTeamManager implements ManagesTeams
{
    public function update(int $teamId, string $name): TeamData
    {
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $team->update(['name' => $name]);

        return new TeamData($team->id, $team->name, $team->slug, $team->is_personal);
    }

    public function membershipForUpdate(int $teamId, int $userId): TeamMembershipData
    {
        [$team, $user] = $this->lockTeamAndUser($teamId, $userId);

        return $this->membershipData($team, $user);
    }

    public function deletionForUpdate(int $teamId, int $userId): TeamDeletionData
    {
        [$team, $user] = $this->lockTeamAndUser($teamId, $userId);

        $affectedUsers = array_values(User::query()
            ->where('current_team_id', $team->id)
            ->whereKeyNot($user->id)
            ->orderBy('id')
            ->get()
            ->map(function (User $affectedUser): array {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($affectedUser->id);

                return [
                    'userId' => $lockedUser->id,
                    'personalTeamId' => $lockedUser->personalTeam()?->id,
                ];
            })
            ->all());

        return new TeamDeletionData(
            $team->id,
            $team->is_personal,
            $this->membershipData($team, $user),
            $affectedUsers,
        );
    }

    public function setCurrentTeam(int $userId, ?int $teamId): void
    {
        User::query()->whereKey($userId)->update(['current_team_id' => $teamId]);
    }

    public function detachMember(int $teamId, int $userId): void
    {
        Team::query()->findOrFail($teamId)
            ->memberships()
            ->where('user_id', $userId)
            ->delete();
    }

    public function deleteTeam(int $teamId): void
    {
        $team = Team::query()->findOrFail($teamId);

        $team->invitations()->delete();
        $team->memberships()->delete();
        $team->delete();
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

    /** @return array{Team, User} */
    private function lockTeamAndUser(int $teamId, int $userId): array
    {
        return [
            Team::query()->lockForUpdate()->findOrFail($teamId),
            User::query()->lockForUpdate()->findOrFail($userId),
        ];
    }

    private function membershipData(Team $team, User $user): TeamMembershipData
    {
        $availableTeams = array_values($user->teams()
            ->orderByRaw('LOWER(teams.name)')
            ->orderBy('teams.id')
            ->get()
            ->map(fn (Team $availableTeam): TeamData => new TeamData(
                $availableTeam->id,
                $availableTeam->name,
                $availableTeam->slug,
                $availableTeam->is_personal,
                $user->teamRole($availableTeam),
                $user->isCurrentTeam($availableTeam),
            ))
            ->all());

        return new TeamMembershipData(
            $team->id,
            $team->is_personal,
            $user->id,
            $user->teamRole($team),
            $user->current_team_id,
            $user->personalTeam()?->id,
            $availableTeams,
        );
    }
}
