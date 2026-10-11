<?php

namespace App\Modules\Teams\Domain\Policies;

use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;

class TeamLifecyclePolicy
{
    public function assertMembership(?TeamRole $role): void
    {
        if ($role === null) {
            throw new TeamOperationRejected('The user does not belong to this team.');
        }
    }

    public function assertPermission(?TeamRole $role, TeamPermission $permission): void
    {
        if (! $role?->hasPermission($permission)) {
            throw new TeamOperationRejected('The user is not permitted to perform this team operation.');
        }
    }

    public function assertCanLeave(bool $isPersonal, ?TeamRole $role): void
    {
        if ($isPersonal) {
            throw new TeamOperationRejected('Personal teams cannot be left.');
        }

        $this->assertMembership($role);

        if ($role === TeamRole::Owner) {
            throw new TeamOperationRejected('Team owners cannot leave their team.');
        }
    }

    public function assertCanDelete(bool $isPersonal, ?TeamRole $role): void
    {
        if ($isPersonal) {
            throw new TeamOperationRejected('Personal teams cannot be deleted.');
        }

        if ($role !== TeamRole::Owner) {
            throw new TeamOperationRejected('Only team owners can delete a team.');
        }
    }

    public function assertCanChangeRole(?TeamRole $actorRole, ?TeamRole $memberRole, TeamRole $newRole): void
    {
        $this->assertPermission($actorRole, TeamPermission::UpdateMember);
        $this->assertMembership($memberRole);

        if ($newRole === TeamRole::Owner) {
            throw new TeamOperationRejected('The team owner role cannot be assigned.');
        }

        if ($memberRole === TeamRole::Owner) {
            throw new TeamOperationRejected('The team owner role cannot be changed.');
        }
    }

    public function assertCanRemove(?TeamRole $actorRole, ?TeamRole $memberRole): void
    {
        $this->assertPermission($actorRole, TeamPermission::RemoveMember);
        $this->assertMembership($memberRole);

        if ($memberRole === TeamRole::Owner) {
            throw new TeamOperationRejected('The team owner cannot be removed.');
        }
    }

    /** @param list<int> $availableTeamIds */
    public function fallbackTeamId(int $teamId, array $availableTeamIds): ?int
    {
        foreach ($availableTeamIds as $availableTeamId) {
            if ($availableTeamId !== $teamId) {
                return $availableTeamId;
            }
        }

        return null;
    }
}
