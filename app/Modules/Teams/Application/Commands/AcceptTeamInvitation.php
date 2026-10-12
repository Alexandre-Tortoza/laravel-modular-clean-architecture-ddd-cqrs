<?php

namespace App\Modules\Teams\Application\Commands;

readonly class AcceptTeamInvitation
{
    public function __construct(public int $userId, public int $invitationId) {}
}
