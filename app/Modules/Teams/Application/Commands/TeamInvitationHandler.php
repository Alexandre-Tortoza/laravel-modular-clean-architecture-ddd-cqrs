<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Data\InvitationResponseData;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Application\Exceptions\TeamInvitationResponseRejected;
use DateTimeImmutable;

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
        $this->transactions->run(function () use ($command): void {
            $invitation = $this->invitations->responseForUpdate($command->userId, $command->invitationId);
            $this->assertCanRespond($invitation);

            $this->invitations->accept($invitation);
        });
    }

    public function decline(DeclineTeamInvitation $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $invitation = $this->invitations->responseForUpdate($command->userId, $command->invitationId);
            $this->assertCanRespond($invitation);

            $this->invitations->decline($command->invitationId);
        });
    }

    public function prune(PruneExpiredTeamInvitations $command): void
    {
        $this->transactions->run(fn (): null => $this->invitations->pruneExpired());
    }

    private function assertCanRespond(InvitationResponseData $invitation): void
    {
        if (mb_strtolower($invitation->recipientEmail) !== mb_strtolower($invitation->userEmail)) {
            throw new TeamInvitationResponseRejected('This invitation was sent to a different email address.');
        }

        if ($invitation->acceptedAt !== null) {
            throw new TeamInvitationResponseRejected('This invitation has already been accepted.');
        }

        if ($invitation->expiresAt !== null && $invitation->expiresAt < new DateTimeImmutable) {
            throw new TeamInvitationResponseRejected('This invitation has expired.');
        }
    }
}
