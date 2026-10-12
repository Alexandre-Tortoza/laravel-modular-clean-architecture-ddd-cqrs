<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Eloquent;

use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Identity\Application\Api\ProfileAccountSnapshot;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class EloquentProfileAccounts implements ProfileAccounts
{
    public function lockOwnProfile(int $actorId): ProfileAccountSnapshot
    {
        $this->requireTransaction();
        $user = User::query()->lockForUpdate()->findOrFail($actorId);

        return new ProfileAccountSnapshot($user->id, $user->email);
    }

    public function saveOwnProfile(int $actorId, string $name, string $email, bool $invalidateEmailVerification): void
    {
        $this->requireTransaction();
        $attributes = ['name' => $name, 'email' => $email];

        if ($invalidateEmailVerification) {
            $attributes['email_verified_at'] = null;
        }

        User::query()->whereKey($actorId)->update($attributes);
    }

    public function deleteOwnProfile(int $actorId): void
    {
        User::query()->findOrFail($actorId)->delete();
    }

    private function requireTransaction(): void
    {
        if (DB::connection((new User)->getConnectionName())->transactionLevel() === 0) {
            throw new LogicException('Profile changes require an active transaction on the User connection.');
        }
    }
}
