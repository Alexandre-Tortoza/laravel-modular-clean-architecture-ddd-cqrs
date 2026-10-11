<?php

namespace App\Modules\Identity\Application\Commands;

use App\Modules\Identity\Application\Contracts\DeletesProfiles;

class DeleteProfileHandler
{
    public function __construct(private DeletesProfiles $profiles) {}

    public function handle(DeleteProfile $command): void
    {
        $this->profiles->delete($command->userId);
    }
}
