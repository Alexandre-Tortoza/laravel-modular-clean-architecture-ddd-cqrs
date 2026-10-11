<?php

namespace App\Modules\Security\Application\Commands;

use App\Modules\Security\Application\Contracts\UpdatesPasswords;

class UpdatePasswordHandler
{
    public function __construct(private UpdatesPasswords $passwords) {}

    public function handle(UpdatePassword $command): void
    {
        $this->passwords->update($command->userId, $command->password);
    }
}
