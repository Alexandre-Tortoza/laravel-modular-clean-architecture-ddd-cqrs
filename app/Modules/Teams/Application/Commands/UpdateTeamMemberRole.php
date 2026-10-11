<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Domain\Enums\TeamRole;

readonly class UpdateTeamMemberRole
{
    public function __construct(public int $actorId, public int $teamId, public int $userId, public TeamRole $role) {}
}
