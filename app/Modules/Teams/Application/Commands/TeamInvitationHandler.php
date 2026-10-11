<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Data\TeamInvitationData;

class TeamInvitationHandler
{
    public function __construct(private ManagesTeamInvitations $invitations, private RunsTeamTransactions $transactions) {}

    public function create(CreateTeamInvitation $command): TeamInvitationData
    {
        return $this->transactions->run(fn (): TeamInvitationData => $this->invitations->create($command->teamId, $command->inviterId, $command->email, $command->role));
    }

    public function cancel(CancelTeamInvitation $command): void
    {
        $this->transactions->run(fn (): null => $this->invitations->cancel($command->teamId, $command->invitationId));
    }

    public function accept(AcceptTeamInvitation $command): void
    {
        $this->transactions->run(fn (): null => $this->invitations->accept($command->userId, $command->invitationId));
    }

    public function decline(DeclineTeamInvitation $command): void
    {
        $this->transactions->run(fn (): null => $this->invitations->decline($command->invitationId));
    }

    public function prune(PruneExpiredTeamInvitations $command): void
    {
        $this->transactions->run(fn (): null => $this->invitations->pruneExpired());
    }
}
