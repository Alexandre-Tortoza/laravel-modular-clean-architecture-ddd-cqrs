<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Eloquent;

use App\Modules\Identity\Application\Contracts\DeletesProfiles;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;

class EloquentProfileDeleter implements DeletesProfiles
{
    public function delete(int $userId): void
    {
        User::query()->findOrFail($userId)->delete();
    }
}
