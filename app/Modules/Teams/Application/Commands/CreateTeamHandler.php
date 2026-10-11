<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\CreatesTeams;
use App\Modules\Teams\Application\Data\TeamData;

class CreateTeamHandler
{
    public function __construct(private CreatesTeams $teams) {}

    public function handle(CreateTeam $command): TeamData
    {
        return $this->teams->create($command->userId, $command->name, $command->isPersonal);
    }
}
