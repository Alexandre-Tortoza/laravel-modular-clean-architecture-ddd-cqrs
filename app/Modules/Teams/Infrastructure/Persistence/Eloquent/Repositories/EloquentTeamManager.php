<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories;

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Identity\Application\Api\UserRecord;
use App\Modules\Teams\Application\Contracts\ManagesAccountTeams;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Data\AccountTeamDeletionData;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Application\Data\TeamDeletionData;
use App\Modules\Teams\Application\Data\TeamMembershipData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Membership;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EloquentTeamManager implements ManagesAccountTeams, ManagesTeams
{
    public function __construct(private UserDirectory $users, private TeamSelectionAccounts $selection) {}

    public function update(int $teamId, string $name): TeamData
    {
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $team->update(['name' => $name]);

        return new TeamData($team->id, $team->name, $team->slug, $team->is_personal);
    }

    public function membershipForUpdate(int $teamId, int $userId): TeamMembershipData
    {
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $user = $this->selection->lockMany([$userId])[$userId];

        return $this->membershipData($team, $user);
    }

    public function membershipsForUpdate(int $teamId, int $actorId, int $userId): array
    {
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $users = $this->selection->lockMany([$actorId, $userId]);

        return [
            'actor' => $this->membershipData($team, $users[$actorId]),
            'member' => $this->membershipData($team, $users[$userId]),
        ];
    }

    public function deletionForUpdate(int $teamId, int $userId): TeamDeletionData
    {
        $candidateTeams = function ($query) use ($teamId, $userId): void {
            $query->whereKey($teamId)->orWhereIn('id', Membership::query()
                ->select('team_id')->where('user_id', $userId));
        };
        $teams = Team::query()->where($candidateTeams)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $team = $teams->get($teamId);

        if ($team === null) {
            throw new ModelNotFoundException;
        }

        $users = $this->selection->lockMany(array_merge([$userId], $this->selection->userIdsSelectingTeam($teamId)));
        $affectedUsers = [];

        foreach ($users as $affectedUser) {
            if ($affectedUser->id !== $userId && $this->users->currentTeamIdFor($affectedUser->id) === $teamId) {
                $affectedUsers[] = [
                    'userId' => $affectedUser->id,
                    'personalTeamId' => $this->personalTeamId($affectedUser->id),
                ];
            }
        }

        return new TeamDeletionData($team->id, $team->is_personal,
            $this->membershipData($team, $users[$userId]), $affectedUsers);
    }

    public function setCurrentTeam(int $userId, ?int $teamId): void
    {
        $this->selection->select($userId, $teamId);
    }

    public function detachMember(int $teamId, int $userId): void
    {
        Membership::query()->where('team_id', $teamId)->where('user_id', $userId)->delete();
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
        Membership::query()->where('team_id', $teamId)->where('user_id', $userId)->firstOrFail()->update(['role' => $role]);
    }

    public function deletionDataForAccount(int $accountId): AccountTeamDeletionData
    {
        $teams = Team::query()->whereIn('id', Membership::query()
            ->select('team_id')->where('user_id', $accountId))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $this->selection->lockMany([$accountId]);
        $memberships = Membership::query()->where('user_id', $accountId)
            ->orderBy('team_id')->lockForUpdate()->get();
        $ownedTeams = [];

        foreach ($memberships as $membership) {
            $team = $teams->get($membership->team_id);

            if ($team === null || $membership->role !== TeamRole::Owner) {
                continue;
            }

            $ownedTeams[] = [
                'id' => $team->id,
                'isPersonal' => $team->is_personal,
                'memberCount' => $team->memberships()->count(),
            ];
        }

        return new AccountTeamDeletionData($ownedTeams);
    }

    public function deleteResourcesForAccount(int $accountId, string $email, array $personalTeamIds): void
    {
        TeamInvitation::query()->where('invited_by', $accountId)
            ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)])->delete();
        Membership::query()->where('user_id', $accountId)->delete();

        foreach ($personalTeamIds as $teamId) {
            $team = Team::query()->findOrFail($teamId);
            $team->invitations()->delete();
            $team->memberships()->delete();
            $team->delete();
        }
    }

    private function membershipData(Team $team, UserRecord $user): TeamMembershipData
    {
        $currentTeamId = $this->users->currentTeamIdFor($user->id);
        $availableTeams = array_values(Team::query()->join('team_members', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.user_id', $user->id)->orderByRaw('LOWER(teams.name)')
            ->orderBy('teams.id')->select('teams.*', 'team_members.role as membership_role')->get()
            ->map(fn (Team $availableTeam): TeamData => new TeamData(
                $availableTeam->id, $availableTeam->name, $availableTeam->slug, $availableTeam->is_personal,
                TeamRole::from($availableTeam->getAttribute('membership_role')), $currentTeamId === $availableTeam->id,
            ))->all());
        $membership = Membership::query()->where('team_id', $team->id)->where('user_id', $user->id)->first();

        return new TeamMembershipData($team->id, $team->is_personal, $user->id, $membership?->role,
            $currentTeamId, $this->personalTeamId($user->id), $availableTeams);
    }

    private function personalTeamId(int $userId): ?int
    {
        $id = Team::query()->join('team_members', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.user_id', $userId)->where('teams.is_personal', true)
            ->where('team_members.role', TeamRole::Owner->value)->value('teams.id');

        return $id === null ? null : (int) $id;
    }
}
