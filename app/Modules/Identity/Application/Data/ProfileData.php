<?php

namespace App\Modules\Identity\Application\Data;

readonly class ProfileData
{
    public function __construct(public int $userId, public string $email) {}
}
