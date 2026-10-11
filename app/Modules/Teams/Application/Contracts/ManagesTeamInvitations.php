<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;

interface ManagesTeamInvitations
{
    public function create(int $teamId, int $inviterId, string $email, TeamRole $role): TeamInvitationData;

    public function cancel(int $teamId, int $invitationId): void;

    public function accept(int $userId, int $invitationId): void;

    public function decline(int $invitationId): void;

    public function pruneExpired(): void;
}
