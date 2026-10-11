<?php

namespace App\Modules\Identity\Application\Commands;

readonly class UpdatePassword
{
    public function __construct(public int $userId, public string $password) {}
}
