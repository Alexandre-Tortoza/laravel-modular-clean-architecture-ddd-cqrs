<?php

namespace App\Modules\Teams\Application\Commands;

readonly class LeaveTeam
{
    public function __construct(public int $userId, public int $teamId) {}
}
