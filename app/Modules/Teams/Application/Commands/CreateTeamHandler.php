<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Teams\Application\Api\PersonalTeams;
use App\Modules\Teams\Application\Contracts\CreatesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Policies\TeamNamePolicy;

class CreateTeamHandler implements PersonalTeams
{
    public function __construct(
        private CreatesTeams $teams,
        private RunsTeamTransactions $transactions,
        private TeamSelectionAccounts $selection,
        private TeamNamePolicy $namePolicy,
    ) {}

    public function createFor(int $userId, string $name): void
    {
        $this->handle(new CreateTeam(userId: $userId, name: $name, isPersonal: true));
    }

    public function handle(CreateTeam $command): TeamData
    {
        $this->namePolicy->assertValid($command->name);

        return $this->transactions->run(function () use ($command): TeamData {
            $this->selection->lockMany([$command->userId]);
            $team = $this->teams->create($command->userId, $command->name, $command->isPersonal);
            $this->selection->select($command->userId, $team->id);

            return new TeamData($team->id, $team->name, $team->slug, $team->isPersonal, TeamRole::Owner, true);
        });
    }
}
