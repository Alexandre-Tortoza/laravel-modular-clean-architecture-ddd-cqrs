<?php

namespace App\Modules\Teams\Application\Commands;

readonly class CancelTeamInvitation
{
    public function __construct(public int $teamId, public int $invitationId) {}
}
