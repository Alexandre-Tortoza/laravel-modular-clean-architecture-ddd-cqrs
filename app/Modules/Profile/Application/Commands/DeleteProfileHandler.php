<?php

namespace App\Modules\Profile\Application\Commands;

use App\Modules\Profile\Application\Contracts\DeletesProfiles;

class DeleteProfileHandler
{
    public function __construct(private DeletesProfiles $profiles) {}

    public function handle(DeleteProfile $command): void
    {
        $this->profiles->delete($command->userId);
    }
}
