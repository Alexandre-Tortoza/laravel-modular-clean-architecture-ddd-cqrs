<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Identity\Application\Api\AccountCredentials;

class UpdatePasswordHandler
{
    public function __construct(private AccountCredentials $accounts) {}

    public function handle(UpdatePassword $command): void
    {
        $this->accounts->changeOwnPassword($command->userId, $command->password);
    }
}
