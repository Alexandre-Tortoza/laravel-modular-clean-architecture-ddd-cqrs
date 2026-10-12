<?php

namespace App\Modules\Security\Application\Commands;

readonly class UpdatePassword
{
    public function __construct(public int $userId, public string $password) {}
}
