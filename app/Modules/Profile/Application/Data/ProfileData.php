<?php

namespace App\Modules\Profile\Application\Data;

readonly class ProfileData
{
    public function __construct(public int $userId, public string $email) {}
}
