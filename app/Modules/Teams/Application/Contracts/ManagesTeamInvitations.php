<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Application\Data\InvitationResponseData;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;

interface ManagesTeamInvitations
{
    public function create(int $teamId, int $inviterId, string $email, TeamRole $role): TeamInvitationData;

    public function cancel(int $teamId, int $invitationId): void;

    public function responseForUpdate(int $userId, int $invitationId): InvitationResponseData;

    public function accept(InvitationResponseData $invitation): void;

    public function decline(int $invitationId): void;

    public function pruneExpired(): void;
}
