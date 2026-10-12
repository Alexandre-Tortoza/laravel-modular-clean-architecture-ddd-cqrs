<?php

namespace App\Modules\Identity\Application\Api;

/** Trusted Security integration. The caller supplies an authenticated executor ID and confirms the current password. */
interface AccountCredentials
{
    public function changeOwnPassword(int $actorId, string $password): void;
}
