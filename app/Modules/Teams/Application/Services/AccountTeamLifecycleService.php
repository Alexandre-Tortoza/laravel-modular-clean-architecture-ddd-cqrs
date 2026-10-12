<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\Teams\Application\Api\AccountTeamDeletionResult;
use App\Modules\Teams\Application\Api\AccountTeamLifecycle;
use App\Modules\Teams\Application\Contracts\ManagesAccountTeams;
use App\Modules\Teams\Domain\Policies\AccountTeamDeletionPolicy;

class AccountTeamLifecycleService implements AccountTeamLifecycle
{
    public function __construct(
        private ManagesAccountTeams $teams,
        private AccountTeamDeletionPolicy $policy,
    ) {}

    public function deleteResourcesForAccount(int $accountId, string $email): AccountTeamDeletionResult
    {
        $deletion = $this->teams->deletionDataForAccount($accountId);
        $message = $this->policy->rejectionMessage($deletion->ownedTeams);

        if ($message !== null) {
            return new AccountTeamDeletionResult(false, $message);
        }

        $personalTeamIds = array_values(array_map(
            fn (array $team): int => $team['id'],
            array_filter($deletion->ownedTeams, fn (array $team): bool => $team['isPersonal']),
        ));
        $this->teams->deleteResourcesForAccount($accountId, $email, $personalTeamIds);

        return new AccountTeamDeletionResult(true);
    }
}
