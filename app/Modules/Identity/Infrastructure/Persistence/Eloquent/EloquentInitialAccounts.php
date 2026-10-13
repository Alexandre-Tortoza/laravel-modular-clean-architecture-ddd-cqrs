<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Eloquent;

use App\Modules\Identity\Application\Api\InitialAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;

class EloquentInitialAccounts implements InitialAccounts
{
    public function exists(): bool
    {
        return User::query()->exists();
    }

    public function create(string $name, string $email, string $password): int
    {
        return User::query()->create(['name' => $name, 'email' => $email, 'password' => $password])->id;
    }
}
