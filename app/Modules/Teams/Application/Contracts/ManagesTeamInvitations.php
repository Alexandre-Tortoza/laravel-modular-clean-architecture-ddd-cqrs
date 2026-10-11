<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Teams\Application\Data\InvitationCreationData;
use App\Modules\Teams\Application\Data\InvitationResponseData;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;

interface ManagesTeamInvitations
{
    public function creationForUpdate(int $teamId, int $inviterId, string $email): InvitationCreationData;

    public function create(int $teamId, int $inviterId, string $email, TeamRole $role, string $inviterName): TeamInvitationData;

    public function cancellationRoleForUpdate(int $teamId, int $actorId, int $invitationId): ?TeamRole;

    public function cancel(int $invitationId): void;

    public function responseForUpdate(int $userId, int $invitationId): InvitationResponseData;

    public function accept(InvitationResponseData $invitation): string;

    public function decline(int $invitationId): void;

    public function pruneExpired(): void;
}
