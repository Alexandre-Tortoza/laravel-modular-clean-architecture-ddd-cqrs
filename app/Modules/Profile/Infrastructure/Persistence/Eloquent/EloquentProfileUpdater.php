<?php

namespace App\Modules\Profile\Infrastructure\Persistence\Eloquent;

use App\Models\User;
use App\Modules\Profile\Application\Contracts\UpdatesProfiles;
use App\Modules\Profile\Application\Data\ProfileData;

class EloquentProfileUpdater implements UpdatesProfiles
{
    public function find(int $userId): ProfileData
    {
        $user = User::query()->findOrFail($userId);

        return new ProfileData($user->id, $user->email);
    }

    public function update(int $userId, string $name, string $email, bool $invalidateEmailVerification): void
    {
        $user = User::query()->findOrFail($userId);
        $user->fill(['name' => $name, 'email' => $email]);

        if ($invalidateEmailVerification) {
            $user->email_verified_at = null;
        }

        $user->save();
    }
}
