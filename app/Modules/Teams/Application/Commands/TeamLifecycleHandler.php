<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Application\Data\TeamMembershipData;
use App\Modules\Teams\Application\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Enums\TeamRole;

class TeamLifecycleHandler
{
    public function __construct(private ManagesTeams $teams, private RunsTeamTransactions $transactions) {}

    public function update(UpdateTeam $command): TeamData
    {
        return $this->transactions->run(fn (): TeamData => $this->teams->update($command->teamId, $command->name));
    }

    public function switchCurrentTeam(SwitchCurrentTeam $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $membership = $this->teams->membershipForUpdate($command->teamId, $command->userId);
            $this->assertMembership($membership);

            $this->teams->setCurrentTeam($command->userId, $command->teamId);
        });
    }

    public function leave(LeaveTeam $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $membership = $this->teams->membershipForUpdate($command->teamId, $command->userId);

            $this->assertCanLeave($membership);
            $fallbackTeamId = $membership->currentTeamId === $membership->teamId
                ? $this->fallbackTeamId($membership)
                : null;

            $this->teams->detachMember($command->teamId, $command->userId);

            if ($fallbackTeamId !== null) {
                $this->teams->setCurrentTeam($command->userId, $fallbackTeamId);
            }
        });
    }

    public function delete(DeleteTeam $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $deletion = $this->teams->deletionForUpdate($command->teamId, $command->userId);

            if ($deletion->teamIsPersonal) {
                throw new TeamOperationRejected('Personal teams cannot be deleted.');
            }

            if ($deletion->actor->role !== TeamRole::Owner) {
                throw new TeamOperationRejected('Only team owners can delete a team.');
            }

            $fallbackTeamId = $deletion->actor->currentTeamId === $deletion->teamId
                ? $this->fallbackTeamId($deletion->actor)
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

    public function updateMemberRole(UpdateTeamMemberRole $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $membership = $this->teams->membershipForUpdate($command->teamId, $command->userId);
            $this->assertMembership($membership);

            if ($command->role === TeamRole::Owner) {
                throw new TeamOperationRejected('The team owner role cannot be assigned.');
            }

            $this->teams->updateMemberRole($command->teamId, $command->userId, $command->role);
        });
    }

    public function removeMember(RemoveTeamMember $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $membership = $this->teams->membershipForUpdate($command->teamId, $command->userId);
            $this->assertMembership($membership);

            if ($membership->role === TeamRole::Owner) {
                throw new TeamOperationRejected('The team owner cannot be removed.');
            }

            if ($membership->currentTeamId === $membership->teamId) {
                $this->teams->setCurrentTeam($command->userId, $membership->personalTeamId);
            }

            $this->teams->detachMember($command->teamId, $command->userId);
        });
    }

    private function assertCanLeave(TeamMembershipData $membership): void
    {
        if ($membership->teamIsPersonal) {
            throw new TeamOperationRejected('Personal teams cannot be left.');
        }

        $this->assertMembership($membership);

        if ($membership->role === TeamRole::Owner) {
            throw new TeamOperationRejected('Team owners cannot leave their team.');
        }
    }

    private function assertMembership(TeamMembershipData $membership): void
    {
        if ($membership->role === null) {
            throw new TeamOperationRejected('The user does not belong to this team.');
        }
    }

    private function fallbackTeamId(TeamMembershipData $membership): ?int
    {
        foreach ($membership->availableTeams as $team) {
            if ($team->id !== $membership->teamId) {
                return $team->id;
            }
        }

        return null;
    }
}
