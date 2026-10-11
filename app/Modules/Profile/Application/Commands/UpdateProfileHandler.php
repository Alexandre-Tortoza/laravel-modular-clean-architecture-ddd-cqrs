<?php

namespace App\Modules\Profile\Application\Commands;

use App\Modules\Profile\Application\Contracts\RunsProfileTransactions;
use App\Modules\Profile\Application\Contracts\UpdatesProfiles;

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
