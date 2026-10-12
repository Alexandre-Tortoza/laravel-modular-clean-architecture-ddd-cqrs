<?php

namespace App\Modules\Identity\Application\Api;

/** Trusted Profile integration. The caller supplies an authenticated executor ID, never a separate target. */
interface ProfileAccounts
{
    /** Atomically updates the actor while protecting email verification. */
    public function updateOwnProfile(int $actorId, string $name, string $email): void;

    public function deleteOwnProfile(int $actorId): void;
}
