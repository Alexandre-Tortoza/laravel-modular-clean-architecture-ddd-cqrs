<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Queries;

use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Application\Data\PendingTeamInvitationData;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Application\Data\TeamEditData;
use App\Modules\Teams\Application\Data\TeamPermissionsData;
use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Membership;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EloquentTeamQueries implements TeamQueries
{
    public function __construct(private TeamClock $clock, private UserDirectory $users, private TeamLifecyclePolicy $lifecycle) {}

    public function forUser(int $userId, bool $includeCurrent = false): array
    {
        $this->ensureUserExists($userId);
        $currentTeamId = $this->users->currentTeamIdFor($userId);

        return array_values(Team::query()
            ->join('team_members', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.user_id', $userId)
            ->orderBy('teams.name')
            ->select('teams.*', 'team_members.role as membership_role')
            ->get()
            ->map(fn (Team $team): TeamData => new TeamData(
                $team->id, $team->name, $team->slug, $team->is_personal,
                TeamRole::from($team->getAttribute('membership_role')), $currentTeamId === $team->id,
            ))
            ->filter(fn (TeamData $team): bool => $includeCurrent || ! $team->isCurrent)
            ->values()->all());
    }

    public function currentForUser(int $userId): ?TeamData
    {
        $this->ensureUserExists($userId);
        $teamId = $this->users->currentTeamIdFor($userId);
        $team = $teamId === null ? null : Team::query()->find($teamId);

        return $team === null ? null : $this->teamData($team, $userId, $teamId);
    }

    public function currentOrPersonalForUser(int $userId): ?TeamData
    {
        $current = $this->currentForUser($userId);

        if ($current !== null) {
            return $current;
        }

        $personal = Team::query()->join('team_members', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.user_id', $userId)->where('teams.is_personal', true)
            ->select('teams.*')->first();

        return $personal === null ? null : $this->teamData($personal, $userId, null);
    }

    public function permissionsFor(int $userId, int $teamId): TeamPermissionsData
    {
        $this->ensureUserExists($userId);
        $team = Team::query()->findOrFail($teamId);
        $role = $this->roleFor($userId, $teamId);

        return new TeamPermissionsData(
            canUpdateTeam: $role?->hasPermission(TeamPermission::UpdateTeam) ?? false,
            canDeleteTeam: $this->lifecycle->canDelete($team->is_personal, $role),
            canAddMember: $role?->hasPermission(TeamPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(TeamPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(TeamPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(TeamPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(TeamPermission::CancelInvitation) ?? false,
        );
    }

    public function editFor(int $userId, int $teamId): TeamEditData
    {
        $this->ensureUserExists($userId);
        if (! $this->belongsToTeam($userId, $teamId)) {
            throw new TeamOperationRejected('The user does not belong to this team.');
        }

        $team = Team::query()->findOrFail($teamId);
        $memberships = $team->memberships()->orderBy('id')->get();
        $members = $this->users->findMany($memberships->pluck('user_id')->all());
        $memberData = [];

        foreach ($memberships as $membership) {
            $member = $members[$membership->user_id] ?? null;

            if ($member === null) {
                continue;
            }

            $memberData[] = [
                'id' => $member->id, 'name' => $member->name, 'email' => $member->email,
                'avatar' => $member->avatar, 'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
            ];
        }

        return new TeamEditData(
            $this->teamData($team, $userId, $this->users->currentTeamIdFor($userId)),
            $memberData,
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
        $invitations = TeamInvitation::query()->with('team')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $this->clock->now()))
            ->latest()->get();
        $inviters = $invitations->isEmpty() ? [] : $this->users->findMany($invitations->pluck('invited_by')->all());

        return array_values($invitations
            ->map(fn (TeamInvitation $invitation): PendingTeamInvitationData => $this->pendingInvitationData(
                $invitation, isset($inviters[$invitation->invited_by]) ? $inviters[$invitation->invited_by]->name : null,
            ))
            ->all());
    }

    public function pendingInvitationByCode(string $code): ?PendingTeamInvitationData
    {
        $invitation = TeamInvitation::query()->with('team')->where('code', $code)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $this->clock->now()))
            ->first();

        return $invitation === null ? null : $this->pendingInvitationData($invitation,
            $this->users->find($invitation->invited_by)?->name);
    }

    public function belongsToTeam(int $userId, int $teamId): bool
    {
        return Membership::query()->where('team_id', $teamId)->where('user_id', $userId)->exists();
    }

    public function roleFor(int $userId, int $teamId): ?TeamRole
    {
        return Membership::query()->where('team_id', $teamId)->where('user_id', $userId)->first()?->role;
    }

    public function hasPermission(int $userId, int $teamId, TeamPermission $permission): bool
    {
        return $this->roleFor($userId, $teamId)?->hasPermission($permission) ?? false;
    }

    public function ownsTeam(int $userId, int $teamId): bool
    {
        return $this->roleFor($userId, $teamId) === TeamRole::Owner;
    }

    private function teamData(Team $team, int $userId, ?int $currentTeamId): TeamData
    {
        return new TeamData($team->id, $team->name, $team->slug, $team->is_personal,
            $this->roleFor($userId, $team->id), $currentTeamId === $team->id);
    }

    private function pendingInvitationData(TeamInvitation $invitation, ?string $inviterName): PendingTeamInvitationData
    {
        return new PendingTeamInvitationData($invitation->code, $invitation->team->name, $invitation->team->slug,
            $inviterName);
    }

    private function ensureUserExists(int $userId): void
    {
        if ($this->users->find($userId) === null) {
            throw new ModelNotFoundException;
        }
    }
}
