<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Application\Data\TeamInvitationData;

interface SendsTeamInvitations
{
    public function send(TeamInvitationData $invitation): void;
}
