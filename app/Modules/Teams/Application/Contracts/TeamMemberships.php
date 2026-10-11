<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;

interface TeamMemberships
{
    public function belongsTo(int $userId, int $teamId): bool;

    public function roleFor(int $userId, int $teamId): ?TeamRole;

    public function can(int $userId, int $teamId, TeamPermission $permission): bool;

    public function switchCurrentTeam(int $userId, int $teamId): bool;
}
