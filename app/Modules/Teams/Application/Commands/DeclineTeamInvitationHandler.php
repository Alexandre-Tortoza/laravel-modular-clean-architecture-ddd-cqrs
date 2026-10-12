<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Domain\Policies\InvitationPolicy;

class DeclineTeamInvitationHandler
{
    public function __construct(private ManagesTeamInvitations $invitations, private RunsTeamTransactions $transactions, private TeamClock $clock, private InvitationPolicy $policy) {}

    public function handle(DeclineTeamInvitation $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $invitation = $this->invitations->responseForUpdate($command->userId, $command->invitationId);
            $this->policy->assertCanRespond($invitation->recipientEmail, $invitation->userEmail,
                $invitation->expiresAt, $invitation->acceptedAt, $this->clock->now());
            $this->invitations->decline($command->invitationId);
        });
    }
}
