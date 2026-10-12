<?php

namespace App\Modules\Teams\Application\Data;

readonly class AccountTeamDeletionData
{
    /** @param list<array{id: int, isPersonal: bool, memberCount: int}> $ownedTeams */
    public function __construct(public array $ownedTeams) {}
}
