<?php

namespace App\Modules\Profile\Application\Commands;

readonly class DeleteProfile
{
    public function __construct(public int $userId, public string $email) {}
}
