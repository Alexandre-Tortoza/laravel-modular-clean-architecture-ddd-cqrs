<?php

namespace App\Modules\Identity\Application\Api;

/** Trusted Profile integration. The caller supplies an authenticated executor ID, never a separate target. */
interface ProfileAccounts
{
    /** Requires the caller's active transaction and locks the actor row. */
    public function lockOwnProfile(int $actorId): ProfileAccountSnapshot;

    /** Requires the same transaction as lockOwnProfile. */
    public function saveOwnProfile(int $actorId, string $name, string $email, bool $invalidateEmailVerification): void;

    public function deleteOwnProfile(int $actorId): void;
}
