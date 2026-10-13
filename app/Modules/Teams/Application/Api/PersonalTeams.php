<?php

namespace App\Modules\Teams\Application\Api;

/** Account bootstrap integration using the existing personal-team use case. */
interface PersonalTeams
{
    public function createFor(int $userId, string $name): void;
}
