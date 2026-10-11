<?php

namespace App\Modules\Teams\Application\Data;

readonly class TeamDeletionData
{
    /** @param list<array{userId: int, personalTeamId: ?int}> $affectedUsers */
    public function __construct(
        public int $teamId,
        public bool $teamIsPersonal,
        public TeamMembershipData $actor,
        public array $affectedUsers,
    ) {}
}
