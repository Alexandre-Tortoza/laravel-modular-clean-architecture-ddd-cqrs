<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Contracts\SendsTeamInvitations;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Application\Data\InvitationResponseData;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Application\Exceptions\TeamInvitationResponseRejected;

class TeamInvitationHandler
{
    public function __construct(
        private ManagesTeamInvitations $invitations,
        private RunsTeamTransactions $transactions,
        private SendsTeamInvitations $sender,
        private TeamClock $clock,
    ) {}

    public function create(CreateTeamInvitation $command): TeamInvitationData
    {
        $invitation = $this->transactions->run(fn (): TeamInvitationData => $this->invitations->create($command->teamId, $command->inviterId, $command->email, $command->role));

        $this->sender->send($invitation);

        return $invitation;
    }

    public function cancel(CancelTeamInvitation $command): void
    {
        $this->transactions->run(fn (): null => $this->invitations->cancel($command->teamId, $command->actorId, $command->invitationId));
    }

    public function accept(AcceptTeamInvitation $command): string
    {
        return $this->transactions->run(function () use ($command): string {
            $invitation = $this->invitations->responseForUpdate($command->userId, $command->invitationId);
            $this->assertCanRespond($invitation);

            return $this->invitations->accept($invitation);
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

        if ($invitation->expiresAt !== null && $invitation->expiresAt <= $this->clock->now()) {
            throw new TeamInvitationResponseRejected('This invitation has expired.');
        }
    }
}
