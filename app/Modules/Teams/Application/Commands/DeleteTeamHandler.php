<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;

class DeleteTeamHandler
{
    public function __construct(private ManagesTeams $teams, private RunsTeamTransactions $transactions, private TeamLifecyclePolicy $policy) {}

    public function handle(DeleteTeam $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $deletion = $this->teams->deletionForUpdate($command->teamId, $command->userId);
            $this->policy->assertCanDelete($deletion->teamIsPersonal, $deletion->actor->role);
            $fallbackTeamId = $deletion->actor->currentTeamId === $deletion->teamId
                ? $this->policy->fallbackTeamId($deletion->teamId, array_map(fn ($team): int => $team->id, $deletion->actor->availableTeams))
                : null;

            foreach ($deletion->affectedUsers as $affectedUser) {
                $this->teams->setCurrentTeam($affectedUser['userId'], $affectedUser['personalTeamId']);
            }

            $this->teams->deleteTeam($command->teamId);

            if ($fallbackTeamId !== null) {
                $this->teams->setCurrentTeam($command->userId, $fallbackTeamId);
            }
        });
    }
}
