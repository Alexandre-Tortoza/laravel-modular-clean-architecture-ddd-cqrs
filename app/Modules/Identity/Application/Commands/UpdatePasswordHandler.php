<?php

namespace App\Modules\Identity\Application\Commands;

use App\Modules\Identity\Application\Contracts\UpdatesPasswords;

class UpdatePasswordHandler
{
    public function __construct(private UpdatesPasswords $passwords) {}

    public function handle(UpdatePassword $command): void
    {
        $this->passwords->update($command->userId, $command->password);
    }
}
