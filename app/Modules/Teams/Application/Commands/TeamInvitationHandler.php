<?php

namespace App\Modules\Teams\Application\Commands;

use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;

class TeamInvitationHandler
{
    public function __construct(private ManagesTeamInvitations $invitations) {}

    public function create(int $teamId, int $inviterId, string $email, TeamRole $role): TeamInvitationData
    {
        return $this->invitations->create($teamId, $inviterId, $email, $role);
    }

    public function cancel(int $teamId, int $invitationId): void
    {
        $this->invitations->cancel($teamId, $invitationId);
    }

    public function accept(int $userId, int $invitationId): void
    {
        $this->invitations->accept($userId, $invitationId);
    }

    public function decline(int $invitationId): void
    {
        $this->invitations->decline($invitationId);
    }

    public function pruneExpired(): void
    {
        $this->invitations->pruneExpired();
    }
}
