<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Data\TeamEditData;
use App\Modules\Teams\Application\Data\TeamPermissionsData;

interface TeamQueries extends TeamContext
{
    public function permissionsFor(int $userId, int $teamId): TeamPermissionsData;

    public function editFor(int $userId, int $teamId): TeamEditData;
}
