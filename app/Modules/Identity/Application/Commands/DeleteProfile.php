<?php

namespace App\Modules\Identity\Application\Commands;

readonly class DeleteProfile
{
    public function __construct(public int $userId) {}
}
