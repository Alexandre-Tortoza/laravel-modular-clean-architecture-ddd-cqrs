<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;

class UpdateTeamMemberRoleHandler
{
    public function __construct(private ManagesTeams $teams, private RunsTeamTransactions $transactions, private TeamLifecyclePolicy $policy) {}

    public function handle(UpdateTeamMemberRole $command): void
    {
        $this->transactions->run(function () use ($command): void {
            ['actor' => $actor, 'member' => $member] = $this->teams->membershipsForUpdate($command->teamId, $command->actorId, $command->userId);
            $this->policy->assertCanChangeRole($actor->role, $member->role, $command->role);
            $this->teams->updateMemberRole($command->teamId, $command->userId, $command->role);
        });
    }
}
