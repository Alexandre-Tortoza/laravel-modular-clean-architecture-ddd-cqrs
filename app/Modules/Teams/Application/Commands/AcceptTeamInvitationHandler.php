<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Domain\Policies\InvitationPolicy;

class AcceptTeamInvitationHandler
{
    public function __construct(private ManagesTeamInvitations $invitations, private RunsTeamTransactions $transactions, private TeamClock $clock, private InvitationPolicy $policy, private TeamSelectionAccounts $selection) {}

    public function handle(AcceptTeamInvitation $command): string
    {
        return $this->transactions->run(function () use ($command): string {
            $invitation = $this->invitations->responseForUpdate($command->userId, $command->invitationId);
            $now = $this->clock->now();
            $this->policy->assertCanRespond($invitation->recipientEmail, $invitation->userEmail,
                $invitation->expiresAt, $invitation->acceptedAt, $now, $invitation->userEmailIsVerified);

            $this->invitations->accept($invitation, $now);
            $this->selection->select($invitation->userId, $invitation->teamId);

            return $invitation->teamSlug;
        });
    }
}
