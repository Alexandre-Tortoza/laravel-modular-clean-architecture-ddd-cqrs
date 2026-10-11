<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Queries;

use App\Models\User;
use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Application\Data\PendingTeamInvitationData;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Application\Data\TeamEditData;
use App\Modules\Teams\Application\Data\TeamPermissionsData;
use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Membership;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;

class EloquentTeamQueries implements TeamQueries
{
    public function forUser(int $userId, bool $includeCurrent = false): array
    {
        $user = User::query()->findOrFail($userId);

        return array_values($user->teams()
            ->orderBy('teams.name')
            ->get()
            ->map(fn (Team $team): TeamData => $this->teamData($user, $team))
            ->filter(fn (TeamData $team): bool => $includeCurrent || ! $team->isCurrent)
            ->values()
            ->all());
    }

    public function currentForUser(int $userId): ?TeamData
    {
        $user = User::query()->findOrFail($userId);
        $team = $user->currentTeam;

        return $team instanceof Team ? $this->teamData($user, $team) : null;
    }

    public function currentOrPersonalForUser(int $userId): ?TeamData
    {
        $user = User::query()->findOrFail($userId);
        $team = $user->currentTeam ?? $user->personalTeam();

        return $team instanceof Team ? $this->teamData($user, $team) : null;
    }

    public function permissionsFor(int $userId, int $teamId): TeamPermissionsData
    {
        $user = User::query()->findOrFail($userId);
        $team = Team::query()->findOrFail($teamId);
        $role = $user->teamRole($team);

        return new TeamPermissionsData(
            canUpdateTeam: $role?->hasPermission(TeamPermission::UpdateTeam) ?? false,
            canDeleteTeam: $role?->hasPermission(TeamPermission::DeleteTeam) ?? false,
            canAddMember: $role?->hasPermission(TeamPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(TeamPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(TeamPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(TeamPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(TeamPermission::CancelInvitation) ?? false,
        );
    }

    public function editFor(int $userId, int $teamId): TeamEditData
    {
        $user = User::query()->findOrFail($userId);
        $team = Team::query()->findOrFail($teamId);

        return new TeamEditData(
            $this->teamData($user, $team),
            array_values($team->members()->get()->map(function (User $member): array {
                $membership = $member->getRelation('pivot');
                $role = $membership instanceof Membership ? $membership->role : TeamRole::Member;

                return [
                    'id' => $member->id, 'name' => $member->name, 'email' => $member->email,
                    'avatar' => $member->avatar, 'role' => $role->value,
                    'role_label' => $role->label(),
                ];
            })->all()),
            array_values($team->invitations()->whereNull('accepted_at')->get()->map(fn (TeamInvitation $invitation): array => [
                'code' => $invitation->code, 'email' => $invitation->email,
                'role' => $invitation->role->value, 'role_label' => $invitation->role->label(),
                'created_at' => $invitation->created_at?->toISOString() ?? '',
            ])->all()),
            $this->permissionsFor($userId, $teamId),
        );
    }

    public function pendingInvitationsForEmail(string $email): array
    {
        return array_values(TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation): PendingTeamInvitationData => $this->pendingInvitationData($invitation))
            ->all());
    }

    public function pendingInvitationByCode(string $code): ?PendingTeamInvitationData
    {
        $invitation = TeamInvitation::query()
            ->with('team')
            ->where('code', $code)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->first();

        return $invitation instanceof TeamInvitation ? $this->pendingInvitationData($invitation) : null;
    }

    private function teamData(User $user, Team $team): TeamData
    {
        return new TeamData(
            id: $team->id,
            name: $team->name,
            slug: $team->slug,
            isPersonal: $team->is_personal,
            role: $user->teamRole($team),
            isCurrent: $user->isCurrentTeam($team),
        );
    }

    private function pendingInvitationData(TeamInvitation $invitation): PendingTeamInvitationData
    {
        return new PendingTeamInvitationData(
            code: $invitation->code,
            teamName: $invitation->team->name,
            teamSlug: $invitation->team->slug,
            inviterName: $invitation->relationLoaded('inviter') ? $invitation->inviter->name : null,
        );
    }
}
