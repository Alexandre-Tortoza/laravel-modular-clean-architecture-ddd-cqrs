<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Contracts\TeamClock;

class PruneExpiredTeamInvitationsHandler
{
    public function __construct(private ManagesTeamInvitations $invitations, private RunsTeamTransactions $transactions, private TeamClock $clock) {}

    public function handle(PruneExpiredTeamInvitations $command): void
    {
        $this->transactions->run(fn (): null => $this->invitations->pruneExpired($this->clock->now()));
    }
}
