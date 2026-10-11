<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Eloquent;

use App\Modules\Identity\Application\Contracts\UpdatesPasswords;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;

class EloquentPasswordUpdater implements UpdatesPasswords
{
    public function update(int $userId, string $password): void
    {
        User::query()->findOrFail($userId)->update(['password' => $password]);
    }
}
