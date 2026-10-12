<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;

class LeaveTeamHandler
{
    public function __construct(private ManagesTeams $teams, private RunsTeamTransactions $transactions, private TeamLifecyclePolicy $policy) {}

    public function handle(LeaveTeam $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $membership = $this->teams->membershipForUpdate($command->teamId, $command->userId);
            $this->policy->assertCanLeave($membership->teamIsPersonal, $membership->role);
            $fallbackTeamId = $membership->currentTeamId === $membership->teamId
                ? $this->policy->fallbackTeamId($membership->teamId, array_map(fn ($team): int => $team->id, $membership->availableTeams))
                : null;
            $this->teams->detachMember($command->teamId, $command->userId);

            if ($fallbackTeamId !== null) {
                $this->teams->setCurrentTeam($command->userId, $fallbackTeamId);
            }
        });
    }
}
