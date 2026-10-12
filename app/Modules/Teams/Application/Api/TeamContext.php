<?php

namespace App\Modules\Teams\Application\Api;

use App\Modules\Teams\Application\Data\PendingTeamInvitationData;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;

/** Trusted read integration. Callers authorize before exposing team data to an actor. */
interface TeamContext
{
    /** @return list<TeamData> */
    public function forUser(int $userId, bool $includeCurrent = false): array;

    public function currentForUser(int $userId): ?TeamData;

    public function currentOrPersonalForUser(int $userId): ?TeamData;

    /** @return list<PendingTeamInvitationData> */
    public function pendingInvitationsForEmail(string $email): array;

    public function pendingInvitationByCode(string $code): ?PendingTeamInvitationData;

    public function belongsToTeam(int $userId, int $teamId): bool;

    public function roleFor(int $userId, int $teamId): ?TeamRole;

    public function hasPermission(int $userId, int $teamId, TeamPermission $permission): bool;

    public function ownsTeam(int $userId, int $teamId): bool;
}
