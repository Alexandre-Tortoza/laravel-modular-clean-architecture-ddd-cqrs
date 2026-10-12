<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Application\Data\TeamData;

interface CreatesTeams
{
    public function create(int $userId, string $name, bool $isPersonal): TeamData;
}
