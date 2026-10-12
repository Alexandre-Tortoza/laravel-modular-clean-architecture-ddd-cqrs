<?php

namespace App\Modules\Identity\Application\Contracts;

interface StoresProfileAccounts
{
    public function emailForUpdate(int $actorId): string;

    public function save(int $actorId, string $name, string $email, bool $invalidateEmailVerification): void;

    public function delete(int $actorId): void;
}
