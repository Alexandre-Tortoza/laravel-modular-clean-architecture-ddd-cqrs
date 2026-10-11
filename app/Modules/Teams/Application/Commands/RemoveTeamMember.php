<?php

namespace App\Modules\Teams\Application\Commands;

readonly class RemoveTeamMember
{
    public function __construct(public int $teamId, public int $userId) {}
}
