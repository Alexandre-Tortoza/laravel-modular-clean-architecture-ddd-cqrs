<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Application\Data\TeamDeletionData;
use App\Modules\Teams\Application\Data\TeamMembershipData;
use App\Modules\Teams\Domain\Enums\TeamRole;

interface ManagesTeams
{
    public function update(int $teamId, string $name): TeamData;

    public function membershipForUpdate(int $teamId, int $userId): TeamMembershipData;

    public function deletionForUpdate(int $teamId, int $userId): TeamDeletionData;

    public function setCurrentTeam(int $userId, ?int $teamId): void;

    public function detachMember(int $teamId, int $userId): void;

    public function deleteTeam(int $teamId): void;

    public function updateMemberRole(int $teamId, int $userId, TeamRole $role): void;
}
