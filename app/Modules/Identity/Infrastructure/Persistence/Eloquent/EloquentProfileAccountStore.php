<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Eloquent;

use App\Modules\Identity\Application\Contracts\StoresProfileAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class EloquentProfileAccountStore implements StoresProfileAccounts
{
    public function emailForUpdate(int $actorId): string
    {
        $this->requireTransaction();
        $user = User::query()->lockForUpdate()->findOrFail($actorId);

        return $user->email;
    }

    public function save(int $actorId, string $name, string $email, bool $invalidateEmailVerification): void
    {
        $this->requireTransaction();
        $attributes = ['name' => $name, 'email' => $email];

        if ($invalidateEmailVerification) {
            $attributes['email_verified_at'] = null;
        }

        User::query()->whereKey($actorId)->update($attributes);
    }

    public function delete(int $actorId): void
    {
        $this->requireTransaction();
        User::query()->findOrFail($actorId)->delete();
    }

    private function requireTransaction(): void
    {
        if (DB::connection((new User)->getConnectionName())->transactionLevel() === 0) {
            throw new LogicException('Profile changes require an active transaction on the User connection.');
        }
    }
}
