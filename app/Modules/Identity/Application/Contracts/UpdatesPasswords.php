<?php

namespace App\Modules\Identity\Application\Contracts;

interface UpdatesPasswords
{
    public function update(int $userId, string $password): void;
}
