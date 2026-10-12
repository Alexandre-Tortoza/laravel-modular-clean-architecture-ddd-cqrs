<?php

namespace App\Modules\Teams\Application\Api;

interface AccountTeamLifecycle
{
    /**
     * Requires an active transaction on the shared local database connection.
     * Reads the authoritative account email under lock. Rejects changed team
     * snapshots or protected ownership without deleting any resources.
     */
    public function deleteResourcesForAccount(int $accountId): AccountTeamDeletionResult;
}
