<?php

namespace App\Modules\Profile\Infrastructure\Persistence\Eloquent;

use App\Models\User;
use App\Modules\Profile\Application\Contracts\UpdatesProfiles;

class EloquentProfileUpdater implements UpdatesProfiles
{
    public function update(int $userId, string $name, string $email): void
    {
        $user = User::query()->findOrFail($userId);
        $user->fill(['name' => $name, 'email' => $email]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
    }
}
