<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;

class UpdateTeamHandler
{
    public function __construct(private ManagesTeams $teams, private RunsTeamTransactions $transactions, private TeamLifecyclePolicy $policy) {}

    public function handle(UpdateTeam $command): TeamData
    {
        return $this->transactions->run(function () use ($command): TeamData {
            $actor = $this->teams->membershipForUpdate($command->teamId, $command->actorId);
            $this->policy->assertPermission($actor->role, TeamPermission::UpdateTeam);

            return $this->teams->update($command->teamId, $command->name);
        });
    }
}
