<?php

namespace App\Modules\Identity\Application\Api;

/** Read-only Security integration for the authenticated executor identified by the caller. */
interface SecurityAccounts
{
    /**
     * @return list<array{id: int, name: string, authenticator: ?string, created_at_diff: string, last_used_at_diff: ?string}>
     */
    public function passkeysFor(int $actorId): array;

    public function hasEnabledTwoFactorFor(int $actorId): bool;
}
