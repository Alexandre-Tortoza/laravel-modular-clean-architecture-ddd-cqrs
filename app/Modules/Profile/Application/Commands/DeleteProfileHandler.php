<?php

namespace App\Modules\Profile\Application\Commands;

use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Profile\Application\Contracts\RunsProfileTransactions;
use App\Modules\Profile\Domain\Exceptions\ProfileDeletionRejected;
use App\Modules\Teams\Application\Api\AccountTeamLifecycle;

class DeleteProfileHandler
{
    public function __construct(
        private ProfileAccounts $accounts,
        private AccountTeamLifecycle $teams,
        private RunsProfileTransactions $transactions,
    ) {}

    public function handle(DeleteProfile $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $result = $this->teams->deleteResourcesForAccount($command->userId, $command->email);

            if (! $result->isAllowed) {
                throw new ProfileDeletionRejected($result->message ?? 'The account cannot be deleted.');
            }

            $this->accounts->deleteOwnProfile($command->userId);
        });
    }
}
