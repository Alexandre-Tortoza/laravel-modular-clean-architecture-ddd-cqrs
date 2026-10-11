<?php

namespace App\Modules\Security\Application\Contracts;

interface UpdatesPasswords
{
    public function update(int $userId, string $password): void;
}
