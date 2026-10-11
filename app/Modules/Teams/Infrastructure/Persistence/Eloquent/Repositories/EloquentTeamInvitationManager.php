<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories;

use App\Models\User;
use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Data\InvitationResponseData;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;

class EloquentTeamInvitationManager implements ManagesTeamInvitations
{
    public function create(int $teamId, int $inviterId, string $email, TeamRole $role): TeamInvitationData
    {
        $invitation = Team::query()->findOrFail($teamId)->invitations()->create(['email' => $email, 'role' => $role, 'invited_by' => $inviterId, 'expires_at' => now()->addDays(3)]);
        $invitation->load(['team', 'inviter']);

        return new TeamInvitationData($invitation->id, $invitation->code, $invitation->email, $invitation->team_id, $invitation->team->name, $invitation->inviter->name, $invitation->role);
    }

    public function cancel(int $teamId, int $invitationId): void
    {
        TeamInvitation::query()->where('team_id', $teamId)->findOrFail($invitationId)->delete();
    }

    public function responseForUpdate(int $userId, int $invitationId): InvitationResponseData
    {
        $invitation = TeamInvitation::query()->lockForUpdate()->findOrFail($invitationId);
        $user = User::query()->lockForUpdate()->findOrFail($userId);

        return new InvitationResponseData(
            $invitation->id,
            $invitation->team_id,
            $user->id,
            $invitation->email,
            $user->email,
            $invitation->role,
            $invitation->expires_at?->toDateTimeImmutable(),
            $invitation->accepted_at?->toDateTimeImmutable(),
        );
    }

    public function accept(InvitationResponseData $invitation): void
    {
        $team = Team::query()->findOrFail($invitation->teamId);

        $team->memberships()->firstOrCreate(
            ['user_id' => $invitation->userId],
            ['role' => $invitation->role],
        );

        TeamInvitation::query()
            ->findOrFail($invitation->invitationId)
            ->update(['accepted_at' => now()]);

        User::query()
            ->whereKey($invitation->userId)
            ->update(['current_team_id' => $invitation->teamId]);
    }

    public function decline(int $invitationId): void
    {
        TeamInvitation::query()->findOrFail($invitationId)->delete();
    }

    public function pruneExpired(): void
    {
        TeamInvitation::query()->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();
    }
}
