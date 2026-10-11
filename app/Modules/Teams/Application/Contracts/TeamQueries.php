<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Application\Data\PendingTeamInvitationData;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Application\Data\TeamEditData;
use App\Modules\Teams\Application\Data\TeamPermissionsData;

interface TeamQueries
{
    /** @return list<TeamData> */
    public function forUser(int $userId, bool $includeCurrent = false): array;

    public function currentForUser(int $userId): ?TeamData;

    public function permissionsFor(int $userId, int $teamId): TeamPermissionsData;

    public function editFor(int $userId, int $teamId): TeamEditData;

    /** @return list<PendingTeamInvitationData> */
    public function pendingInvitationsForEmail(string $email): array;

    public function pendingInvitationByCode(string $code): ?PendingTeamInvitationData;
}
