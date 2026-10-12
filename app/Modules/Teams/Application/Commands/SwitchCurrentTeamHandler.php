<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;

class SwitchCurrentTeamHandler
{
    public function __construct(private ManagesTeams $teams, private RunsTeamTransactions $transactions, private TeamLifecyclePolicy $policy) {}

    public function handle(SwitchCurrentTeam $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $membership = $this->teams->membershipForUpdate($command->teamId, $command->userId);
            $this->policy->assertMembership($membership->role);
            $this->teams->setCurrentTeam($command->userId, $command->teamId);
        });
    }
}
