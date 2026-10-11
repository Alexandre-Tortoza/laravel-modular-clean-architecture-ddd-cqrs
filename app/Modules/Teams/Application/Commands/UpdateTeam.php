<?php

namespace App\Modules\Teams\Application\Commands;

readonly class UpdateTeam
{
    public function __construct(public int $actorId, public int $teamId, public string $name) {}
}
