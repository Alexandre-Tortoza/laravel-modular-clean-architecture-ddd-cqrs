<?php

namespace App\Modules\Profile\Infrastructure\Persistence\Eloquent;

use App\Models\User;
use App\Modules\Profile\Application\Contracts\UpdatesProfiles;
use App\Modules\Profile\Application\Data\ProfileData;

class EloquentProfileUpdater implements UpdatesProfiles
{
    public function findForUpdate(int $userId): ProfileData
    {
        $user = User::query()->lockForUpdate()->findOrFail($userId);

        return new ProfileData($user->id, $user->email);
    }

    public function update(int $userId, string $name, string $email, bool $invalidateEmailVerification): void
    {
        $attributes = ['name' => $name, 'email' => $email];

        if ($invalidateEmailVerification) {
            $attributes['email_verified_at'] = null;
        }

        User::query()->whereKey($userId)->update($attributes);
    }
}
