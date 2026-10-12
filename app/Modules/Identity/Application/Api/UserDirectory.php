<?php

namespace App\Modules\Identity\Application\Api;

/** Trusted read integration; callers authorize the actor before exposing user records. */
interface UserDirectory
{
    public function find(int $id): ?UserRecord;

    public function findByEmail(string $email): ?UserRecord;

    /** @return array<int, UserRecord> */
    public function findAllByEmail(string $email): array;

    /**
     * @param  array<int, int>  $ids
     * @return array<int, UserRecord>
     */
    public function findMany(array $ids): array;

    public function currentTeamIdFor(int $userId): ?int;
}
