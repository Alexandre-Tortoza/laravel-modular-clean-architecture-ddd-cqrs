<?php

namespace App\Modules\Teams\Domain\Policies;

use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamInvitationCreationRejected;
use App\Modules\Teams\Domain\Exceptions\TeamInvitationResponseRejected;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use DateTimeImmutable;

class InvitationPolicy
{
    /** @param list<?DateTimeImmutable> $pendingExpiresAt */
    public function assertCanCreate(?TeamRole $inviterRole, TeamRole $requestedRole, bool $recipientIsMember, array $pendingExpiresAt, DateTimeImmutable $now): void
    {
        $this->assertCanManage($inviterRole, TeamPermission::CreateInvitation);

        if (! \in_array($requestedRole, [TeamRole::Admin, TeamRole::Member], true)) {
            throw new TeamOperationRejected('The owner role cannot be assigned through an invitation.');
        }

        if ($recipientIsMember) {
            throw new TeamInvitationCreationRejected('This user is already a member of the team.');
        }

        foreach ($pendingExpiresAt as $expiresAt) {
            if ($expiresAt === null || $expiresAt > $now) {
                throw new TeamInvitationCreationRejected('An invitation has already been sent to this email address.');
            }
        }
    }

    public function assertCanManage(?TeamRole $actorRole, TeamPermission $permission): void
    {
        if (! $actorRole?->hasPermission($permission)) {
            throw new TeamOperationRejected('You are not allowed to manage invitations for this team.');
        }
    }

    public function assertCanRespond(string $recipientEmail, string $userEmail, ?DateTimeImmutable $expiresAt, ?DateTimeImmutable $acceptedAt, DateTimeImmutable $now): void
    {
        if (mb_strtolower($recipientEmail) !== mb_strtolower($userEmail)) {
            throw new TeamInvitationResponseRejected('This invitation was sent to a different email address.');
        }

        if ($acceptedAt !== null) {
            throw new TeamInvitationResponseRejected('This invitation has already been accepted.');
        }

        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new TeamInvitationResponseRejected('This invitation has expired.');
        }
    }
}
