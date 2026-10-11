<?php

use App\Modules\Teams\Application\Commands\TeamInvitationHandler;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (TeamInvitationHandler $handler): void {
    $handler->pruneExpired();
})->daily()->description('Delete expired team invitations');
