<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Application\Data\AccountTeamDeletionData;

interface ManagesAccountTeams
{
    public function deletionDataForAccount(int $accountId): AccountTeamDeletionData;

    /** @param list<int> $personalTeamIds */
    public function deleteResourcesForAccount(int $accountId, string $email, array $personalTeamIds): void;
}
