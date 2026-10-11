<?php

namespace App\Modules\Identity\Application\Commands;

use App\Modules\Identity\Application\Contracts\RunsProfileTransactions;
use App\Modules\Identity\Application\Contracts\UpdatesProfiles;

class UpdateProfileHandler
{
    public function __construct(private UpdatesProfiles $profiles, private RunsProfileTransactions $transactions) {}

    public function handle(UpdateProfile $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $profile = $this->profiles->findForUpdate($command->userId);

            $this->profiles->update(
                $command->userId,
                $command->name,
                $command->email,
                $profile->email !== $command->email,
            );
        });
    }
}
