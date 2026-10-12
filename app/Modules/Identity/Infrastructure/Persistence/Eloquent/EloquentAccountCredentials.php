<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Eloquent;

use App\Modules\Identity\Application\Api\AccountCredentials;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;

class EloquentAccountCredentials implements AccountCredentials
{
    public function changeOwnPassword(int $actorId, string $password): void
    {
        User::query()->findOrFail($actorId)->update(['password' => $password]);
    }
}
