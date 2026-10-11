<?php

namespace App\Modules\Profile\Application\Contracts;

interface UpdatesProfiles
{
    public function update(int $userId, string $name, string $email): void;
}
