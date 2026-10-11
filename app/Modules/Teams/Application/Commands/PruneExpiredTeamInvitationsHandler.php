<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;

class PruneExpiredTeamInvitationsHandler
{
    public function __construct(private ManagesTeamInvitations $invitations, private RunsTeamTransactions $transactions) {}

    public function handle(PruneExpiredTeamInvitations $command): void
    {
        $this->transactions->run(fn (): null => $this->invitations->pruneExpired());
    }
}
