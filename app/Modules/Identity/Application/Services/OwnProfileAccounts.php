<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Identity\Application\Contracts\RunsIdentityTransactions;
use App\Modules\Identity\Application\Contracts\StoresProfileAccounts;
use App\Modules\Identity\Domain\Policies\EmailVerificationPolicy;

class OwnProfileAccounts implements ProfileAccounts
{
    public function __construct(
        private StoresProfileAccounts $accounts,
        private RunsIdentityTransactions $transactions,
        private EmailVerificationPolicy $verificationPolicy,
    ) {}

    public function updateOwnProfile(int $actorId, string $name, string $email): void
    {
        $this->transactions->run(function () use ($actorId, $name, $email): void {
            $currentEmail = $this->accounts->emailForUpdate($actorId);
            $this->accounts->save($actorId, $name, $email,
                $this->verificationPolicy->shouldInvalidate($currentEmail, $email));
        });
    }

    public function deleteOwnProfile(int $actorId): void
    {
        $this->accounts->delete($actorId);
    }
}
