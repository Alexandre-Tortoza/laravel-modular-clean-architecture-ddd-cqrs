<?php

namespace App\Modules\Teams\Application\Commands;

readonly class CreateTeam
{
    public function __construct(
        public int $userId,
        public string $name,
        public bool $isPersonal = false,
    ) {}
}
