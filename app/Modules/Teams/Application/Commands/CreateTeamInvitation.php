<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Domain\Enums\TeamRole;

readonly class CreateTeamInvitation
{
    public function __construct(
        public int $teamId,
        public int $inviterId,
        public string $email,
        public TeamRole $role,
    ) {}
}
