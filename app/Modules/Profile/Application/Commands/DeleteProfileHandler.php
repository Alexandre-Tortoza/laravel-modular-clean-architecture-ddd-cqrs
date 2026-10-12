<?php

namespace App\Modules\Profile\Application\Commands;

use App\Modules\Identity\Application\Api\ProfileAccounts;

class DeleteProfileHandler
{
    public function __construct(private ProfileAccounts $accounts) {}

    public function handle(DeleteProfile $command): void
    {
        $this->accounts->deleteOwnProfile($command->userId);
    }
}
