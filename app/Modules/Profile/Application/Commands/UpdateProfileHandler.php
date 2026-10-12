<?php

namespace App\Modules\Profile\Application\Commands;

use App\Modules\Identity\Application\Api\ProfileAccounts;

class UpdateProfileHandler
{
    public function __construct(private ProfileAccounts $accounts) {}

    public function handle(UpdateProfile $command): void
    {
        $this->accounts->updateOwnProfile($command->userId, $command->name, $command->email);
    }
}
