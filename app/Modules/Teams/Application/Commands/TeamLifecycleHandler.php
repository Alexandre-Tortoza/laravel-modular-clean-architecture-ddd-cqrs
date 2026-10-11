<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Data\TeamData;

class TeamLifecycleHandler
{
    public function __construct(private ManagesTeams $teams) {}

    public function update(UpdateTeam $command): TeamData
    {
        return $this->teams->update($command->teamId, $command->name);
    }

    public function switchCurrentTeam(SwitchCurrentTeam $command): void
    {
        $this->teams->switchCurrentTeam($command->userId, $command->teamId);
    }

    public function leave(LeaveTeam $command): void
    {
        $this->teams->leave($command->userId, $command->teamId);
    }

    public function delete(DeleteTeam $command): void
    {
        $this->teams->delete($command->userId, $command->teamId);
    }

    public function updateMemberRole(UpdateTeamMemberRole $command): void
    {
        $this->teams->updateMemberRole($command->teamId, $command->userId, $command->role);
    }

    public function removeMember(RemoveTeamMember $command): void
    {
        $this->teams->removeMember($command->teamId, $command->userId);
    }
}
