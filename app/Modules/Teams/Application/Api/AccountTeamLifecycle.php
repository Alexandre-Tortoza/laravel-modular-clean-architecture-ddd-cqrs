<?php

namespace App\Modules\Teams\Application\Api;

interface AccountTeamLifecycle
{
    /** Requires an active transaction on the shared local database connection. */
    public function deleteResourcesForAccount(int $accountId, string $email): AccountTeamDeletionResult;
}
