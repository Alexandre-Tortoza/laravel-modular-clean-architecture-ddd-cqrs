<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Teams\Application\Contracts\CreatesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;

class CreateTeamHandler
{
    public function __construct(private CreatesTeams $teams, private RunsTeamTransactions $transactions, private TeamSelectionAccounts $selection) {}

    public function handle(CreateTeam $command): TeamData
    {
        return $this->transactions->run(function () use ($command): TeamData {
            $this->selection->lockMany([$command->userId]);
            $team = $this->teams->create($command->userId, $command->name, $command->isPersonal);
            $this->selection->select($command->userId, $team->id);

            return new TeamData($team->id, $team->name, $team->slug, $team->isPersonal, TeamRole::Owner, true);
        });
    }
}
