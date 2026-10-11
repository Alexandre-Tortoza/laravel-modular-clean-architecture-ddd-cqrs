<?php

namespace App\Modules\Profile\Application\Commands;

readonly class UpdateProfile
{
    public function __construct(public int $userId, public string $name, public string $email) {}
}
