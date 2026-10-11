<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Domain\Policies\InvitationPolicy;

class AcceptTeamInvitationHandler
{
    public function __construct(private ManagesTeamInvitations $invitations, private RunsTeamTransactions $transactions, private TeamClock $clock, private InvitationPolicy $policy) {}

    public function handle(AcceptTeamInvitation $command): string
    {
        return $this->transactions->run(function () use ($command): string {
            $invitation = $this->invitations->responseForUpdate($command->userId, $command->invitationId);
            $this->policy->assertCanRespond($invitation->recipientEmail, $invitation->userEmail,
                $invitation->expiresAt, $invitation->acceptedAt, $this->clock->now());

            return $this->invitations->accept($invitation);
        });
    }
}
