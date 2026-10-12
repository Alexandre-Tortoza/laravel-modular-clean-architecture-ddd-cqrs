<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;

class RemoveTeamMemberHandler
{
    public function __construct(private ManagesTeams $teams, private RunsTeamTransactions $transactions, private TeamLifecyclePolicy $policy) {}

    public function handle(RemoveTeamMember $command): void
    {
        $this->transactions->run(function () use ($command): void {
            ['actor' => $actor, 'member' => $member] = $this->teams->membershipsForUpdate($command->teamId, $command->actorId, $command->userId);
            $this->policy->assertCanRemove($actor->role, $member->role);

            if ($member->currentTeamId === $member->teamId) {
                $this->teams->setCurrentTeam($command->userId, $member->personalTeamId);
            }

            $this->teams->detachMember($command->teamId, $command->userId);
        });
    }
}
