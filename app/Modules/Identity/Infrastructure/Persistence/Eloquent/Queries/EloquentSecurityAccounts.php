<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Eloquent\Queries;

use App\Modules\Identity\Application\Api\SecurityAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;

class EloquentSecurityAccounts implements SecurityAccounts
{
    public function passkeysFor(int $actorId): array
    {
        return array_values(User::query()->findOrFail($actorId)
            ->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])->all());
    }

    public function hasEnabledTwoFactorFor(int $actorId): bool
    {
        return User::query()->findOrFail($actorId)->hasEnabledTwoFactorAuthentication();
    }
}
