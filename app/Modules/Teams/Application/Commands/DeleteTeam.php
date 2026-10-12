<?php

namespace App\Modules\Teams\Application\Commands;

readonly class DeleteTeam
{
    public function __construct(public int $userId, public int $teamId) {}
}
