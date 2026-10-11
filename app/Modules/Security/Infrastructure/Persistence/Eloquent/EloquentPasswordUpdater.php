<?php

namespace App\Modules\Security\Infrastructure\Persistence\Eloquent;

use App\Models\User;
use App\Modules\Security\Application\Contracts\UpdatesPasswords;

class EloquentPasswordUpdater implements UpdatesPasswords
{
    public function update(int $userId, string $password): void
    {
        User::query()->findOrFail($userId)->update(['password' => $password]);
    }
}
