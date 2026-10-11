<?php

use App\Modules\Teams\Application\Commands\PruneExpiredTeamInvitations;
use App\Modules\Teams\Application\Commands\TeamInvitationHandler;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (TeamInvitationHandler $handler): void {
    $handler->prune(new PruneExpiredTeamInvitations);
})->daily()->description('Delete expired team invitations');
