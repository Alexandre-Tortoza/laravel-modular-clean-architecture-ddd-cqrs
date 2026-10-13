<?php

namespace App\Modules\Identity\Application\Api;

/** Account bootstrap integration. The caller validates input and coordinates its transaction. */
interface InitialAccounts
{
    public function exists(): bool;

    public function create(string $name, string $email, string $password): int;
}
