<?php

namespace App\Modules\Teams\Infrastructure\Notifications;

use App\Modules\Teams\Application\Contracts\SendsTeamInvitations;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use Illuminate\Support\Facades\Notification;

class TeamInvitationSender implements SendsTeamInvitations
{
    public function send(TeamInvitationData $invitation): void
    {
        Notification::route('mail', $invitation->email)
            ->notify((new TeamInvitation($invitation))->afterCommit());
    }
}
