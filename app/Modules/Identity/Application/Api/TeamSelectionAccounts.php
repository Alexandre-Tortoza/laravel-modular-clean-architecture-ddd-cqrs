<?php

namespace App\Modules\Identity\Application\Api;

/** Integration reserved for Teams write operations within the caller's transaction. */
interface TeamSelectionAccounts
{
    /**
     * Locks existing users in ascending ID order on the active transaction connection.
     *
     * @param  list<int>  $ids
     * @return array<int, UserRecord>
     */
    public function lockMany(array $ids): array;

    /** @return list<int> */
    public function userIdsSelectingTeam(int $teamId): array;

    public function select(int $userId, ?int $teamId): void;
}
