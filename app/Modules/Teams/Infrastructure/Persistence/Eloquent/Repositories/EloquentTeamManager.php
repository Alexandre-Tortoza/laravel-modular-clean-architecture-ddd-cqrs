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

    /** @return array{actor: TeamMembershipData, member: TeamMembershipData} */
    public function membershipsForUpdate(int $teamId, int $actorId, int $userId): array
    {
        [$team, $users] = $this->lockTeamAndUsers($teamId, [$actorId, $userId]);

        return [
            'actor' => $this->membershipData($team, $users[$actorId]),
            'member' => $this->membershipData($team, $users[$userId]),
        ];
    }

    public function deletionForUpdate(int $teamId, int $userId): TeamDeletionData
    {
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $affectedUserIds = User::query()->where('current_team_id', $teamId)->pluck('id')->map(fn (int $id): int => $id)->values()->all();
        $users = $this->lockUsers(array_merge([$userId], $affectedUserIds));
        $user = $users[$userId];
        $affectedUsers = [];

        foreach ($users as $affectedUser) {
            if ($affectedUser->id !== $userId && $affectedUser->current_team_id === $teamId) {
                $affectedUsers[] = [
                    'userId' => $affectedUser->id,
                    'personalTeamId' => $affectedUser->personalTeam()?->id,
                ];
            }
        }

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
        [$team, $users] = $this->lockTeamAndUsers($teamId, [$userId]);

        return [$team, $users[$userId]];
    }

    /**
     * @param  list<int>  $userIds
     * @return array{Team, array<int, User>}
     */
    private function lockTeamAndUsers(int $teamId, array $userIds): array
    {
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);

        return [$team, $this->lockUsers($userIds)];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, User>
     */
    private function lockUsers(array $userIds): array
    {
        $sortedUserIds = array_values(array_unique($userIds));
        sort($sortedUserIds, SORT_NUMERIC);
        $users = [];

        foreach ($sortedUserIds as $userId) {
            $users[$userId] = User::query()->lockForUpdate()->findOrFail($userId);
        }

        return $users;
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
