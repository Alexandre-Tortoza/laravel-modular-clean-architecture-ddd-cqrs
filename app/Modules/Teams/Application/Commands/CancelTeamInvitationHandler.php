<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Policies\InvitationPolicy;

class CancelTeamInvitationHandler
{
    public function __construct(private ManagesTeamInvitations $invitations, private RunsTeamTransactions $transactions, private InvitationPolicy $policy) {}

    public function handle(CancelTeamInvitation $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $role = $this->invitations->cancellationRoleForUpdate($command->teamId, $command->actorId, $command->invitationId);
            $this->policy->assertCanManage($role, TeamPermission::CancelInvitation);
            $this->invitations->cancel($command->invitationId);
        });
    }
}
