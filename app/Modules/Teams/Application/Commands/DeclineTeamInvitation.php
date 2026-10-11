<?php

namespace App\Modules\Teams\Application\Commands;

readonly class DeclineTeamInvitation
{
    public function __construct(public int $invitationId) {}
}
