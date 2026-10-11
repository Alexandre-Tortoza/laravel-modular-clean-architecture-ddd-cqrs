<?php

namespace App\Modules\Identity\Application\Api;

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

    /**
     * Lock existing users in ascending ID order inside the caller's transaction.
     *
     * @param  array<int, int>  $ids
     * @return array<int, UserRecord>
     */
    public function lockMany(array $ids): array;
}
