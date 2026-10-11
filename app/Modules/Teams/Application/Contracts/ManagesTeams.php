<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;

interface ManagesTeams
{
    public function update(int $teamId, string $name): TeamData;

    public function switchCurrentTeam(int $userId, int $teamId): void;

    public function leave(int $userId, int $teamId): void;

    public function delete(int $userId, int $teamId): void;

    public function updateMemberRole(int $teamId, int $userId, TeamRole $role): void;

    public function removeMember(int $teamId, int $userId): void;
}
