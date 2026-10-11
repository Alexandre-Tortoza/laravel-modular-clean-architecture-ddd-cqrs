<?php

namespace App\Modules\Profile\Infrastructure\Persistence\Eloquent;

use App\Models\User;
use App\Modules\Profile\Application\Contracts\DeletesProfiles;

class EloquentProfileDeleter implements DeletesProfiles
{
    public function delete(int $userId): void
    {
        User::query()->findOrFail($userId)->delete();
    }
}
