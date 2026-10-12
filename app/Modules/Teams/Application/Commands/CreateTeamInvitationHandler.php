<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Contracts\SendsTeamInvitations;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Policies\InvitationPolicy;

class CreateTeamInvitationHandler
{
    public function __construct(
        private ManagesTeamInvitations $invitations,
        private RunsTeamTransactions $transactions,
        private SendsTeamInvitations $sender,
        private InvitationPolicy $policy,
        private TeamClock $clock,
    ) {}

    public function handle(CreateTeamInvitation $command): TeamInvitationData
    {
        $invitation = $this->transactions->run(function () use ($command): TeamInvitationData {
            $state = $this->invitations->creationForUpdate($command->teamId, $command->inviterId, $command->email);
            $this->policy->assertCanCreate($state->inviterRole, $command->role, $state->recipientIsMember, $state->pendingExpiresAt, $this->clock->now());

            return $this->invitations->create($command->teamId, $command->inviterId, $command->email, $command->role, $state->inviterName);
        });

        $this->sender->send($invitation);

        return $invitation;
    }
}
