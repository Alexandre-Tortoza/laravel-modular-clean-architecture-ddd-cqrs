<?php

namespace App\Modules\Teams\Application\Data;

use App\Modules\Teams\Domain\Enums\TeamRole;

readonly class TeamMembershipData
{
    /** @param list<TeamData> $availableTeams */
    public function __construct(
        public int $teamId,
        public bool $teamIsPersonal,
        public int $userId,
        public ?TeamRole $role,
        public ?int $currentTeamId,
        public ?int $personalTeamId,
        public array $availableTeams,
    ) {}
}
