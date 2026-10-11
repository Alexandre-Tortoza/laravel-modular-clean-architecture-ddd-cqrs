<?php

namespace App\Modules\Profile\Application\Commands;

use App\Modules\Profile\Application\Contracts\UpdatesProfiles;

class UpdateProfileHandler
{
    public function __construct(private UpdatesProfiles $profiles) {}

    public function handle(UpdateProfile $command): void
    {
        $this->profiles->update($command->userId, $command->name, $command->email);
    }
}
