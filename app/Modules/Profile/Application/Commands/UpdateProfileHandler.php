<?php

namespace App\Modules\Profile\Application\Commands;

use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Profile\Application\Contracts\RunsProfileTransactions;
use App\Modules\Profile\Domain\Policies\EmailVerificationPolicy;

class UpdateProfileHandler
{
    public function __construct(
        private ProfileAccounts $accounts,
        private RunsProfileTransactions $transactions,
        private EmailVerificationPolicy $verificationPolicy,
    ) {}

    public function handle(UpdateProfile $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $account = $this->accounts->lockOwnProfile($command->userId);
            $this->accounts->saveOwnProfile(
                $command->userId,
                $command->name,
                $command->email,
                $this->verificationPolicy->shouldInvalidate($account->email, $command->email),
            );
        });
    }
}
